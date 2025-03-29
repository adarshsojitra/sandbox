<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Site extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid',
        'name',
        'domain',
        'description',
        'selected_server_id',
        'php_version',
        'server_id',
        'status',
        'site_data',
        'reminder',
        'email',
        'application_id',
        'system_username',
        'wp_username',
        'database_name',
        'cloudflare_record_id',
        'has_dns_record',
        'database_id',
        'database_username',
        'database_password',
        'database_host',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'site_data' => 'array',
        'reminder' => 'boolean',
        'has_dns_record' => 'boolean',
    ];

    /**
     * Bootstrap the model and its traits.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Generate a UUID when creating a new site
        static::creating(function ($site) {
            if (!$site->uuid) {
                $site->uuid = Str::random(32);
            }
        });
    }

    /**
     * Get the server that the site belongs to.
     *
     * @return BelongsTo
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(SelectedServer::class, 'selected_server_id');
    }
    
    /**
     * Get the subdomain portion of the domain
     *
     * @return string
     */
    public function getSubdomainAttribute(): string
    {
        $domainParts = explode('.', $this->domain);
        if (count($domainParts) > 2) {
            return $domainParts[0];
        }
        return '';
    }
}
