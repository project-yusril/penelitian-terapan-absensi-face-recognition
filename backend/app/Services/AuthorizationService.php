<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AuthorizationService
{
    private const RANKS = [
        'super_admin' => 100,
        'ketua_jurusan' => 80,
        'admin_jurusan' => 70,
        'kaprodi' => 60,
        'admin_prodi' => 50,
        'dosen' => 30,
        'mahasiswa' => 10,
        'orang_tua' => 10,
    ];

    private const ASSIGNABLE = [
        'super_admin' => ['super_admin', 'ketua_jurusan', 'admin_jurusan', 'kaprodi', 'admin_prodi', 'dosen', 'mahasiswa', 'orang_tua'],
        'admin_jurusan' => ['admin_prodi', 'dosen', 'mahasiswa', 'orang_tua'],
        'admin_prodi' => ['dosen', 'mahasiswa', 'orang_tua'],
    ];

    public function isSuperAdmin(User $actor): bool
    {
        return $actor->hasRole('super_admin');
    }

    public function scopeUsers(Builder $query, User $actor): Builder
    {
        if ($this->isSuperAdmin($actor)) {
            return $query;
        }

        if ($this->usesProdiScope($actor)) {
            return $actor->prodi_id
                ? $query->where('prodi_id', $actor->prodi_id)
                : $this->denyAll($query);
        }

        if ($actor->hasRole('dosen')) {
            return $query->whereHas('mataKuliahs', fn (Builder $mataKuliah) => $mataKuliah->where('dosen_id', $actor->id));
        }

        return $this->denyAll($query);
    }

    public function scopeProdis(Builder $query, User $actor): Builder
    {
        if ($this->isSuperAdmin($actor)) {
            return $query;
        }

        if ($this->usesProdiScope($actor)) {
            return $actor->prodi_id
                ? $query->whereKey($actor->prodi_id)
                : $this->denyAll($query);
        }

        if ($actor->hasRole('dosen')) {
            return $query->whereHas('mataKuliahs', fn (Builder $mataKuliah) => $mataKuliah->where('dosen_id', $actor->id));
        }

        return $this->denyAll($query);
    }

    public function scopeMataKuliahs(Builder $query, User $actor): Builder
    {
        if ($this->isSuperAdmin($actor)) {
            return $query;
        }

        if ($this->usesProdiScope($actor)) {
            return $actor->prodi_id
                ? $query->where('prodi_id', $actor->prodi_id)
                : $this->denyAll($query);
        }

        if ($actor->hasRole('dosen')) {
            return $query->where('dosen_id', $actor->id);
        }

        return $this->denyAll($query);
    }

    public function scopeAttendances(Builder $query, User $actor): Builder
    {
        if ($this->isSuperAdmin($actor)) {
            return $query;
        }

        if ($this->usesProdiScope($actor)) {
            return $actor->prodi_id
                ? $query->whereHas('mataKuliah', fn (Builder $mataKuliah) => $mataKuliah->where('prodi_id', $actor->prodi_id))
                : $this->denyAll($query);
        }

        if ($actor->hasRole('dosen')) {
            return $query->whereHas('mataKuliah', fn (Builder $mataKuliah) => $mataKuliah->where('dosen_id', $actor->id));
        }

        return $this->denyAll($query);
    }

    public function assertCanManageSystemSettings(User $actor): void
    {
        abort_unless($this->isSuperAdmin($actor), 403);
    }

    private function usesProdiScope(User $actor): bool
    {
        return $actor->hasAnyRole(['ketua_jurusan', 'admin_jurusan', 'kaprodi', 'admin_prodi']);
    }

    private function denyAll(Builder $query): Builder
    {
        return $query->whereRaw('1 = 0');
    }

    public function assertCanCreateUser(User $actor, array $roles, ?int $prodiId): void
    {
        $this->assertAssignableRoles($actor, $roles);
        $this->assertProdi($actor, $prodiId);
    }

    public function assertCanManageUser(User $actor, User $target): void
    {
        if ($this->isSuperAdmin($actor)) {
            return;
        }

        abort_if($actor->id === $target->id, 403);
        abort_unless($actor->hasAnyRole(['admin_jurusan', 'admin_prodi']), 403);
        abort_unless($this->highestRank($actor) > $this->highestRank($target), 403);

        if ($actor->hasAnyRole(['admin_jurusan', 'admin_prodi'])) {
            abort_unless($actor->prodi_id && $target->prodi_id === $actor->prodi_id, 403);
        }
    }

    public function assertCanUpdateUser(User $actor, User $target, ?array $roles, mixed $prodiId, bool $prodiFieldPresent = false): void
    {
        $this->assertCanManageUser($actor, $target);
        if ($roles !== null) {
            $this->assertAssignableRoles($actor, $roles);
        }
        if ($prodiFieldPresent) {
            $this->assertProdi($actor, $prodiId === null ? null : (int) $prodiId);
        }
    }

    public function assertCanApproveProdiResource(User $actor, ?int $prodiId): void
    {
        if ($this->isSuperAdmin($actor)) {
            return;
        }

        abort_unless($actor->hasRole('kaprodi') && $actor->prodi_id, 403);
        abort_unless($prodiId !== null && $prodiId === $actor->prodi_id, 403);
    }

    public function assertCanAccessProdi(User $actor, ?int $prodiId, array $roles): void
    {
        if ($this->isSuperAdmin($actor)) {
            return;
        }

        abort_unless($actor->hasAnyRole($roles) && $actor->prodi_id, 403);
        abort_unless($prodiId !== null && $prodiId === $actor->prodi_id, 403);
    }

    public function requiredApprovalProdi(User $actor): ?int
    {
        if ($this->isSuperAdmin($actor)) {
            return null;
        }

        abort_unless($actor->hasRole('kaprodi') && $actor->prodi_id, 403);

        return $actor->prodi_id;
    }

    public function assignableRoleNames(User $actor): array
    {
        foreach (self::ASSIGNABLE as $role => $assignable) {
            if ($actor->hasRole($role)) {
                return $assignable;
            }
        }

        return [];
    }

    private function assertAssignableRoles(User $actor, array $roles): void
    {
        abort_if($roles === [], 403);
        abort_unless(array_diff($roles, $this->assignableRoleNames($actor)) === [], 403);
    }

    private function assertProdi(User $actor, ?int $prodiId): void
    {
        if ($this->isSuperAdmin($actor)) {
            return;
        }

        abort_unless($actor->hasAnyRole(['admin_jurusan', 'admin_prodi']) && $actor->prodi_id, 403);
        abort_unless($prodiId !== null && $prodiId === $actor->prodi_id, 403);
    }

    private function highestRank(User $user): int
    {
        $names = $user->relationLoaded('roles') ? $user->roles->pluck('name') : $user->roles()->pluck('name');

        return $names->map(fn (string $name) => self::RANKS[$name] ?? 0)->max() ?? 0;
    }
}
