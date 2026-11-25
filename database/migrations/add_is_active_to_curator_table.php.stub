<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Curator tablosuna aktif/pasif bayrağı ekler (zaten varsa dokunmaz).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('curator', 'is_active')) {
            Schema::table('curator', function (Blueprint $table) {
                $table->boolean('is_active')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('curator', 'is_active')) {
            Schema::table('curator', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }
    }
};
