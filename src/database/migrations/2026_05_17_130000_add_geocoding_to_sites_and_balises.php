<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute les colonnes de géocodage administratif (pays, région,
 * département) aux tables `sites` et `balises`. Alimentées par les
 * services `App\Services\Geocoding\*` (BAN + Nominatim hybride).
 *
 * Le champ legacy `sites.region` (libellé libre) est conservé tel quel
 * pour rétrocompat — les nouveaux filtres utilisent uniquement les
 * colonnes normalisées ci-dessous. Cf. FF_location_enrichment.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sites', 'balises'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->char('country_code', 2)->nullable()->after('longitude');
                $t->string('country', 80)->nullable()->after('country_code');
                $t->string('admin_region', 120)->nullable()->after('country');
                $t->string('department', 120)->nullable()->after('admin_region');
                $t->string('geocoded_provider', 20)->nullable()->after('department');
                $t->timestamp('geocoded_at')->nullable()->after('geocoded_provider');

                $t->index('country_code');
                $t->index('department');
                $t->index(['country_code', 'admin_region']);
            });
        }
    }

    public function down(): void
    {
        foreach (['sites', 'balises'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex($table . '_country_code_index');
                $t->dropIndex($table . '_department_index');
                $t->dropIndex($table . '_country_code_admin_region_index');
                $t->dropColumn([
                    'country_code', 'country', 'admin_region', 'department',
                    'geocoded_provider', 'geocoded_at',
                ]);
            });
        }
    }
};
