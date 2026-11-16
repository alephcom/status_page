<?php

use App\Support\RolesAndPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles now live in Spatie's tables: create the roles and permissions, give
 * each user the role from the old users.role column, then drop the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        RolesAndPermissions::sync();

        $roles = Role::query()->where('guard_name', 'web')->pluck('id', 'name');

        DB::table('users')->whereNotNull('role')->orderBy('id')->each(function (object $user) use ($roles) {
            if (isset($roles[$user->role])) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $roles[$user->role],
                    'model_type' => 'App\\Models\\User',
                    'model_id' => $user->id,
                ]);
            }
        });

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('role')->nullable());

        DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->get(['model_has_roles.model_id', 'roles.name'])
            ->each(fn (object $row) => DB::table('users')->where('id', $row->model_id)->update(['role' => $row->name]));
    }
};
