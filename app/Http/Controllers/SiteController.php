<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SelectedServer;
use App\Services\CloudflareService;
use App\Services\ServerAvatarService;
use App\Services\SystemSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SiteController extends Controller
{
    /**
     * The SystemSettingsService instance.
     *
     * @var \App\Services\SystemSettingsService
     */
    protected $systemSettings;

    /**
     * The ServerAvatarService instance.
     *
     * @var \App\Services\ServerAvatarService
     */
    protected $serverAvatarService;

    /**
     * The CloudflareService instance.
     *
     * @var \App\Services\CloudflareService
     */
    protected $cloudflareService;

    /**
     * Create a new controller instance.
     *
     * @param \App\Services\SystemSettingsService $systemSettings
     * @param \App\Services\ServerAvatarService $serverAvatarService
     * @param \App\Services\CloudflareService $cloudflareService
     * @return void
     */
    public function __construct(
        SystemSettingsService $systemSettings, 
        ServerAvatarService $serverAvatarService,
        CloudflareService $cloudflareService
    ) {
        $this->systemSettings = $systemSettings;
        $this->serverAvatarService = $serverAvatarService;
        $this->cloudflareService = $cloudflareService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $sites = Site::with('server')->latest()->get();
        $servers = SelectedServer::all();
        $domain = $this->systemSettings->getDomain();
        
        return view('admin.sites', compact('sites', 'servers', 'domain'));
    }

    /**
     * Show the form for creating a new resource (redirects to index with modal).
     */
    public function create()
    {
        return redirect()->route('admin.sites.index')
            ->with('openCreateModal', true);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Dump input for debugging
        Log::debug('Site creation request', [
            'input' => $request->all(),
            'errors' => session('errors') ? session('errors')->toArray() : null
        ]);
        
        // Get the full domain to check for uniqueness
        $subdomain = $request->input('subdomain');
        if (!$subdomain) {
            return redirect()->route('admin.sites.index')
                ->withErrors(['subdomain' => 'Subdomain is required'])
                ->withInput()
                ->with('openCreateModal', true)
                ->with('error', 'Subdomain is required');
        }
        
        $domain = $subdomain . '.' . $this->systemSettings->getDomain();
        
        $validator = validator($request->all(), [
            'subdomain' => 'required|string|max:255|regex:/^[a-z0-9-]+$/',
            'email' => 'nullable|email|required_if:reminder,on',
            'reminder' => 'nullable|string',
        ], [
            'subdomain.regex' => 'The subdomain may only contain lowercase letters, numbers, and hyphens.',
            'subdomain.unique' => 'This subdomain is already taken. Please choose another one.',
            'email.required_if' => 'The email field is required when reminder is enabled.',
        ]);
        
        if ($validator->fails()) {
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                    'message' => 'Please fix the validation errors below.'
                ], 422);
            }
            
            return redirect()->route('admin.sites.index')
                ->withErrors($validator)
                ->withInput()
                ->with('openCreateModal', true)
                ->with('error', 'Please fix the validation errors below.');
        }
        
        // Check if domain already exists
        if (Site::where('domain', $domain)->exists()) {
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'errors' => ['subdomain' => ['This subdomain is already taken. Please choose another one.']],
                    'message' => 'This subdomain is already taken.'
                ], 422);
            }
            
            return redirect()->route('admin.sites.index')
                ->withErrors(['subdomain' => 'This subdomain is already taken. Please choose another one.'])
                ->withInput()
                ->with('openCreateModal', true)
                ->with('error', 'Please fix the validation errors below.');
        }
        
        $validated = $validator->validated();
        
        // Check if API service is configured
        if (!$this->serverAvatarService->isConfigured()) {
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'ServerAvatar API is not properly configured. Please check API settings.'
                ], 500);
            }
            
            return redirect()->route('admin.sites.index')
                ->with('error', 'ServerAvatar API is not properly configured. Please check API settings.')
                ->withInput()
                ->with('openCreateModal', true);
        }
        
        // Get all available connected servers
        $servers = SelectedServer::where('connection_status', 'connected')->get();
        
        if ($servers->isEmpty()) {
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No connected server available to create site. Please add a connected server first.'
                ], 500);
            }
            
            return redirect()->route('admin.sites.index')
                ->with('error', 'No connected server available to create site. Please add a connected server first.')
                ->withInput()
                ->with('openCreateModal', true);
        }
        
        // Randomize the server list
        $serverIds = $servers->pluck('id')->shuffle();
        $createResponse = null;
        $usedServer = null;
        
        // Try each server until one works or we run out of servers
        foreach ($serverIds as $serverId) {
            $server = SelectedServer::find($serverId);
            if (!$server) {
                continue;
            }
            
            $usedServer = $server;
            
            // Log the attempt
            Log::debug('Attempting to create WordPress site on server', [
                'server_id' => $server->server_id,
                'domain' => $domain,
                'selected_server' => $server->toArray()
            ]);

            // Create WordPress site via ServerAvatar API
            $createResponse = $this->serverAvatarService->createWordPressSite(
                $server->server_id,
                $domain
            );
            
            // If successful, also fetch database information
            if ($createResponse['success'] && isset($createResponse['data']['application']['id'])) {
                Log::debug('WordPress site created, now fetching database information');
                $databaseResponse = $this->serverAvatarService->getDatabaseInformation(
                    $server->server_id,
                    $createResponse['data']['application']['id']
                );
                
                if ($databaseResponse['success']) {
                    Log::info('Database information retrieved successfully', [
                        'database_id' => $databaseResponse['data']['database_id'] ?? null,
                        'database_name' => $databaseResponse['data']['database_name'] ?? null,
                        'full_data' => $databaseResponse['data']
                    ]);
                    
                    // Add more extensive logging for debugging
                    if (!empty($databaseResponse['data']['database_id'])) {
                        Log::debug('Successfully captured database ID: ' . $databaseResponse['data']['database_id']);
                    } else {
                        Log::warning('Failed to capture database ID from application data');
                    }
                    
                    // Merge database information into the create response
                    $createResponse['data']['database'] = $databaseResponse['data'];
                } else {
                    Log::warning('Failed to retrieve database information', [
                        'error' => $databaseResponse['message']
                    ]);
                }
            }
            
            // If successful or specific errors that won't be fixed by trying another server, break
            if ($createResponse['success'] || 
                (isset($createResponse['error_code']) && $createResponse['error_code'] === 'duplicate_domain') ||
                (strpos(strtolower($createResponse['message'] ?? ''), 'duplicate domain') !== false) ||
                (strpos(strtolower($createResponse['message'] ?? ''), 'domain name found') !== false)) {
                break;
            }
            
            // If we get here, this server failed with a 500 error, mark it as in maintenance
            SelectedServer::where('id', $server->id)->update(['connection_status' => 'maintenance']);
            Log::warning('Server failed to create WordPress site, trying next server', [
                'server_id' => $server->server_id,
                'error' => $createResponse['message'] ?? 'Unknown error'
            ]);
        }
        
        // If all servers failed, return an error
        if (!$createResponse || !$usedServer) {
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'All available servers failed to create the WordPress site. Please try again later.'
                ], 500);
            }
            
            return redirect()->route('admin.sites.index')
                ->with('error', 'All available servers failed to create the WordPress site. Please try again later.')
                ->withInput()
                ->with('openCreateModal', true);
        }
        
        // Set $server to the one that was used successfully or last tried
        $server = $usedServer;
        
        if (!$createResponse['success']) {
            $errorMessage = 'Failed to create WordPress site: ' . $createResponse['message'];
            Log::error($errorMessage, ['response' => $createResponse]);
            
            // Special handling for duplicate domain error
            if (isset($createResponse['error_code']) && $createResponse['error_code'] === 'duplicate_domain') {
                if ($request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'errors' => ['subdomain' => ['This subdomain is already taken. Please choose another one.']],
                        'message' => 'This domain name is already in use. Please choose a different subdomain.'
                    ], 422);
                }
                
                return redirect()->route('admin.sites.index')
                    ->withErrors(['subdomain' => 'This subdomain is already taken. Please choose another one.'])
                    ->withInput()
                    ->with('openCreateModal', true)
                    ->with('error', 'This domain name is already in use. Please choose a different subdomain.');
            }
            
            // Handle server errors during WordPress installation
            if (isset($createResponse['error_code']) && $createResponse['error_code'] === 'server_error') {
                // Try a different server next time
                SelectedServer::where('id', $server->id)->update(['connection_status' => 'maintenance']);
                
                // Log the issue
                Log::error('Server error during WordPress installation', [
                    'server_id' => $server->server_id,
                    'selected_server_id' => $server->id,
                    'domain' => $domain
                ]);
                
                if ($request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The server encountered an error during WordPress installation. Please try again with a different subdomain.'
                    ], 500);
                }
                
                return redirect()->route('admin.sites.index')
                    ->withInput()
                    ->with('openCreateModal', true)
                    ->with('error', 'The server encountered an error during WordPress installation. Please try again with a different subdomain.');
            }
            
            // Check if this is a domain uniqueness error based on message content
            if (strpos(strtolower($createResponse['message']), 'duplicate domain') !== false ||
                strpos(strtolower($createResponse['message']), 'domain name found') !== false) {
                
                if ($request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'errors' => ['subdomain' => ['This subdomain is already taken. Please choose another one.']],
                        'message' => 'Domain already exists. Please choose a different subdomain.'
                    ], 422);
                }
                
                return redirect()->route('admin.sites.index')
                    ->withErrors(['subdomain' => 'This subdomain is already taken. Please choose another one.'])
                    ->withInput()
                    ->with('openCreateModal', true)
                    ->with('error', 'Domain already exists. Please choose a different subdomain.');
            }
            
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $errorMessage
                ], 500);
            }
            
            return redirect()->route('admin.sites.index')
                ->with('error', $errorMessage)
                ->withInput()
                ->with('openCreateModal', true);
        }
        
        // Get the application data
        $applicationData = $createResponse['data']['application'] ?? null;
        
        if (!$applicationData || !isset($applicationData['id'])) {
            $errorMessage = 'Missing application data in API response';
            Log::error($errorMessage, ['response' => $createResponse]);
            
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $errorMessage
                ], 500);
            }
            
            return redirect()->route('admin.sites.index')
                ->with('error', $errorMessage)
                ->withInput()
                ->with('openCreateModal', true);
        }
        
        // Install SSL certificate - wrapped in try/catch to prevent complete failure if SSL installation fails
        try {
            Log::info('Attempting to install SSL certificate', [
                'server_id' => $server->server_id,
                'application_id' => $applicationData['id'],
                'use_custom_ssl' => true
            ]);
            
            // First try with custom SSL
            $sslResponse = $this->serverAvatarService->installSSL(
                $server->server_id,
                $applicationData['id'],
                true, // Use custom SSL if available
                true  // Force HTTPS
            );
            
            if (!$sslResponse['success']) {
                // Log the error but try again with automatic SSL
                Log::warning('Failed to install custom SSL certificate: ' . $sslResponse['message'], [
                    'server_id' => $server->server_id,
                    'application_id' => $applicationData['id'],
                    'response' => $sslResponse
                ]);
                
                // Fall back to automatic SSL
                Log::info('Falling back to automatic SSL installation');
                $sslResponse = $this->serverAvatarService->installSSL(
                    $server->server_id,
                    $applicationData['id'],
                    false, // Use automatic SSL
                    true   // Force HTTPS
                );
                
                if (!$sslResponse['success']) {
                    // Both custom and automatic SSL failed
                    Log::error('Failed to install both custom and automatic SSL certificate: ' . $sslResponse['message'], [
                        'server_id' => $server->server_id,
                        'application_id' => $applicationData['id'],
                        'response' => $sslResponse
                    ]);
                    $sslInstalled = false;
                } else {
                    $sslInstalled = true;
                    $sslType = 'automatic';
                    Log::info('Successfully installed automatic SSL certificate');
                }
            } else {
                $sslInstalled = true;
                $sslType = 'custom';
                Log::info('Successfully installed custom SSL certificate');
            }
        } catch (\Exception $e) {
            // Catch any exceptions and log them, but continue with site creation
            Log::error('Exception during SSL installation: ' . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $sslInstalled = false;
            $sslType = null;
        }
        
        // Store credentials for reference
        $credentials = $createResponse['data']['credentials'] ?? [];
        
        // Get database information
        $databaseInfo = $createResponse['data']['database'] ?? [];
        
        // Prepare site data
        $siteData = [
            'name' => $validated['subdomain'],
            'domain' => $domain,
            'selected_server_id' => $server->id,
            'server_id' => $server->server_id,
            'status' => 'active',
            'uuid' => Str::random(32),
            'php_version' => $applicationData['php_version'] ?? '8.2',
            'reminder' => isset($validated['reminder']) && $validated['reminder'] === 'on',
            'email' => isset($validated['reminder']) && $validated['reminder'] === 'on' ? $validated['email'] : null,
            // Store ServerAvatar application details in separate columns for easier access
            'application_id' => $applicationData['id'] ?? null,
            'system_username' => $credentials['system_username'] ?? null,
            'wp_username' => $credentials['wp_username'] ?? null,
            'database_name' => $credentials['database_name'] ?? null,
            // Store database details from API response
            'database_id' => $databaseInfo['database_id'] ?? null,
            'database_username' => $databaseInfo['database_username'] ?? null,
            'database_password' => $databaseInfo['database_password'] ?? null,
            'database_host' => $databaseInfo['database_host'] ?? 'localhost',
            // Also keep in site_data for backward compatibility and to store passwords
            'site_data' => [
                'application_id' => $applicationData['id'] ?? null,
                'wp_username' => $credentials['wp_username'] ?? null,
                'wp_password' => $credentials['wp_password'] ?? null,
                'system_username' => $credentials['system_username'] ?? null,
                'system_password' => $credentials['system_password'] ?? null,
                'database_name' => $credentials['database_name'] ?? null,
                'database_id' => $databaseInfo['database_id'] ?? null,
                'database_username' => $databaseInfo['database_username'] ?? null,
                'database_password' => $databaseInfo['database_password'] ?? null,
                'database_host' => $databaseInfo['database_host'] ?? 'localhost',
                'created_at' => now()->toDateTimeString(),
                'ssl_installed' => $sslInstalled ?? false,
                'ssl_type' => $sslType ?? null,
                'ssl_installation_attempted' => true,
            ],
        ];
        
        // Create the site record in our database
        $site = Site::create($siteData);

        // Create DNS record in Cloudflare if Cloudflare integration is configured
        if ($this->cloudflareService->isConfigured() && !empty($server->ip_address)) {
            try {
                Log::info('Creating DNS A record for new site', [
                    'domain' => $site->domain,
                    'subdomain' => $validated['subdomain'],
                    'ip_address' => $server->ip_address
                ]);

                // Create the DNS record
                $dnsResponse = $this->cloudflareService->createARecord(
                    $validated['subdomain'], // Just the subdomain portion
                    $server->ip_address,    // The server's IP address
                    true                    // Proxied through Cloudflare
                );

                if ($dnsResponse['success']) {
                    // Store the DNS record ID for future reference
                    $site->update([
                        'cloudflare_record_id' => $dnsResponse['record_id'],
                        'has_dns_record' => true
                    ]);

                    // Add DNS record information to site_data
                    $siteData = $site->site_data;
                    $siteData['dns_record'] = [
                        'created_at' => now()->toDateTimeString(),
                        'record_id' => $dnsResponse['record_id'],
                        'type' => 'A',
                        'name' => $site->domain,
                        'ip_address' => $server->ip_address
                    ];
                    $site->site_data = $siteData;
                    $site->save();

                    Log::info('Successfully created DNS A record', [
                        'record_id' => $dnsResponse['record_id'],
                        'domain' => $site->domain
                    ]);
                } else {
                    Log::warning('Failed to create DNS A record', [
                        'domain' => $site->domain,
                        'error' => $dnsResponse['message']
                    ]);
                }
            } catch (\Exception $e) {
                // Log the error but don't fail the entire site creation
                Log::error('Exception during DNS record creation: ' . $e->getMessage(), [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        } else {
            Log::info('Skipping DNS record creation - Cloudflare not configured or server IP missing', [
                'cloudflare_configured' => $this->cloudflareService->isConfigured(),
                'server_ip' => $server->ip_address ?? 'missing'
            ]);
        }

        // Before redirecting, try to fetch database credentials using the database-users endpoint
        if (!empty($site->database_id) && !empty($site->server_id)) {
            try {
                Log::info('Fetching database credentials from database-users endpoint before redirecting', [
                    'site_id' => $site->id,
                    'database_id' => $site->database_id,
                    'server_id' => $site->server_id
                ]);
                
                // Use the dedicated method to get database users with credentials
                $dbUsersResponse = $this->serverAvatarService->getDatabaseUsers(
                    $site->server_id,
                    $site->database_id
                );
                
                if ($dbUsersResponse['success']) {
                    // Extract credentials from the first database user
                    $dbUsername = $dbUsersResponse['data']['database_username'] ?? null;
                    $dbPassword = $dbUsersResponse['data']['database_password'] ?? null;
                    
                    Log::info('Retrieved database user credentials', [
                        'has_username' => !empty($dbUsername),
                        'has_password' => !empty($dbPassword)
                    ]);
                    
                    if (!empty($dbUsername) || !empty($dbPassword)) {
                        // Update the site record with the credentials
                        $updateData = [];
                        
                        if (!empty($dbUsername)) {
                            $updateData['database_username'] = $dbUsername;
                        }
                        
                        if (!empty($dbPassword)) {
                            $updateData['database_password'] = $dbPassword;
                        }
                        
                        if (!empty($updateData)) {
                            Log::info('Updating site with database credentials', [
                                'site_id' => $site->id,
                                'updating_fields' => array_keys($updateData)
                            ]);
                            
                            // Update the database fields
                            $site->update($updateData);
                            
                            // Also update the site_data array for consistency
                            $siteData = $site->site_data;
                            if (!empty($dbUsername)) {
                                $siteData['database_username'] = $dbUsername;
                            }
                            if (!empty($dbPassword)) {
                                $siteData['database_password'] = $dbPassword;
                            }
                            $site->site_data = $siteData;
                            $site->save();
                            
                            // Refresh the site object to get the updated data
                            $site->refresh();
                            
                            Log::info('Successfully updated site with database credentials');
                        }
                    } else {
                        Log::warning('Database user credentials were empty');
                    }
                } else {
                    Log::warning('Failed to get database users from ServerAvatar API: ' . ($dbUsersResponse['message'] ?? 'Unknown error'));
                }
            } catch (\Exception $e) {
                Log::error('Exception while fetching database users: ' . $e->getMessage(), [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                // Continue with redirect even if we couldn't get the credentials
            }
        } else {
            Log::warning('Could not fetch database credentials - missing database_id or server_id', [
                'site_id' => $site->id,
                'has_database_id' => !empty($site->database_id),
                'has_server_id' => !empty($site->server_id)
            ]);
        }
        
        // Check if this is an AJAX request
        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'WordPress site created successfully!',
                'redirect' => route('admin.sites.show', $site->uuid)
            ]);
        }

        // For regular form submissions, redirect as usual
        return redirect()->route('admin.sites.index')
            ->with('success', 'WordPress site created successfully!');
    }

    /**
     * Display the specified resource.
     */
    public function show($uuid)
    {
        $site = Site::where('uuid', $uuid)->firstOrFail();
        $site->load('server');
        return view('admin.sites.show', compact('site'));
    }

    // Edit and update functionality removed since sites cannot be edited once created

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Site $site)
    {
        $warnings = [];
        $serverDeleteSuccess = true;
        $dnsDeleteSuccess = true;
        $databaseDeleteSuccess = true;

        // Try to delete the application from ServerAvatar if application_id is available
        if ($site->application_id && $site->server_id) {
            try {
                $deleteResponse = $this->serverAvatarService->deleteApplication(
                    $site->server_id,
                    $site->application_id
                );
                
                if (!$deleteResponse['success']) {
                    Log::warning('Failed to delete application from ServerAvatar', [
                        'site_id' => $site->id,
                        'server_id' => $site->server_id,
                        'application_id' => $site->application_id,
                        'error' => $deleteResponse['message'] ?? 'Unknown error'
                    ]);
                    
                    $serverDeleteSuccess = false;
                    $warnings[] = 'There was an issue removing the site from the server: ' . 
                        ($deleteResponse['message'] ?? 'Unknown error');
                } else {
                    Log::info('Application deleted from ServerAvatar', [
                        'site_id' => $site->id,
                        'server_id' => $site->server_id,
                        'application_id' => $site->application_id
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Exception while deleting application from ServerAvatar', [
                    'site_id' => $site->id,
                    'server_id' => $site->server_id,
                    'application_id' => $site->application_id,
                    'exception' => $e->getMessage()
                ]);
                
                $serverDeleteSuccess = false;
                $warnings[] = 'There was an error removing the site from the server: ' . $e->getMessage();
            }
        }
        
        // Try to delete the database if database_id is available
        if ($site->database_id && $site->server_id) {
            try {
                Log::info('Deleting database from ServerAvatar', [
                    'site_id' => $site->id,
                    'server_id' => $site->server_id,
                    'database_id' => $site->database_id,
                    'database_name' => $site->database_name
                ]);
                
                $databaseResponse = $this->serverAvatarService->deleteDatabase(
                    $site->server_id,
                    $site->database_id,
                    $site->application_id // Pass application ID to first remove the database from the application
                );
                
                if (!$databaseResponse['success']) {
                    Log::warning('Failed to delete database from ServerAvatar', [
                        'site_id' => $site->id,
                        'server_id' => $site->server_id,
                        'database_id' => $site->database_id,
                        'error' => $databaseResponse['message'] ?? 'Unknown error'
                    ]);
                    
                    $databaseDeleteSuccess = false;
                    $warnings[] = 'There was an issue removing the database from the server: ' . 
                        ($databaseResponse['message'] ?? 'Unknown error');
                } else {
                    Log::info('Database deleted from ServerAvatar', [
                        'site_id' => $site->id,
                        'server_id' => $site->server_id,
                        'database_id' => $site->database_id,
                        'database_name' => $site->database_name
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Exception while deleting database from ServerAvatar', [
                    'site_id' => $site->id,
                    'server_id' => $site->server_id,
                    'database_id' => $site->database_id,
                    'exception' => $e->getMessage()
                ]);
                
                $databaseDeleteSuccess = false;
                $warnings[] = 'There was an error removing the database from the server: ' . $e->getMessage();
            }
        }

        // Delete DNS record from Cloudflare if available
        if ($site->has_dns_record && $site->cloudflare_record_id) {
            try {
                Log::info('Deleting DNS record from Cloudflare', [
                    'site_id' => $site->id,
                    'domain' => $site->domain,
                    'record_id' => $site->cloudflare_record_id
                ]);

                $dnsResponse = $this->cloudflareService->deleteDnsRecord($site->cloudflare_record_id);
                
                if (!$dnsResponse['success']) {
                    Log::warning('Failed to delete DNS record from Cloudflare', [
                        'site_id' => $site->id,
                        'domain' => $site->domain,
                        'record_id' => $site->cloudflare_record_id,
                        'error' => $dnsResponse['message'] ?? 'Unknown error'
                    ]);
                    
                    $dnsDeleteSuccess = false;
                    $warnings[] = 'There was an issue removing the DNS record from Cloudflare: ' . 
                        ($dnsResponse['message'] ?? 'Unknown error');
                } else {
                    Log::info('DNS record deleted from Cloudflare', [
                        'site_id' => $site->id,
                        'domain' => $site->domain,
                        'record_id' => $site->cloudflare_record_id
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Exception while deleting DNS record from Cloudflare', [
                    'site_id' => $site->id,
                    'domain' => $site->domain,
                    'record_id' => $site->cloudflare_record_id,
                    'exception' => $e->getMessage()
                ]);
                
                $dnsDeleteSuccess = false;
                $warnings[] = 'There was an error removing the DNS record from Cloudflare: ' . $e->getMessage();
            }
        }
        
        // Delete the site from our database
        $site->delete();
        
        // Determine the appropriate response message
        if ($serverDeleteSuccess && $dnsDeleteSuccess && $databaseDeleteSuccess) {
            return redirect()->route('admin.sites.index')
                ->with('success', 'Site deleted successfully from our database, the server, database, and DNS records.');
        } elseif (!empty($warnings)) {
            return redirect()->route('admin.sites.index')
                ->with('warning', 'Site deleted from our database, but with issues: ' . implode(' ', $warnings));
        } else {
            return redirect()->route('admin.sites.index')
                ->with('success', 'Site deleted successfully.');
        }
    }
}
