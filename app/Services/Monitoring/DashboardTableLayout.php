<?php

namespace App\Services\Monitoring;

use App\Models\User;

class DashboardTableLayout
{
    /**
     * @param  list<array<string, mixed>>  $targets
     * @return array{visible: list<array<string, mixed>>, hidden: list<array<string, mixed>>}
     */
    public function partition(array $targets, User $user): array
    {
        $byId = [];
        foreach ($targets as $target) {
            $byId[(int) $target['id']] = $target;
        }

        $prefs = $this->normalized($user, array_keys($byId));
        $hidden = array_flip($prefs['hidden']);

        $visible = [];
        $hiddenRows = [];

        foreach ($prefs['order'] as $id) {
            $row = $byId[$id] ?? null;
            if ($row === null) {
                continue;
            }

            if (isset($hidden[$id])) {
                $hiddenRows[] = $row;
                continue;
            }

            $visible[] = $row;
        }

        return [
            'visible' => $visible,
            'hidden' => $hiddenRows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     */
    public function move(User $user, array $targets, int $id, int $delta): void
    {
        $ids = $this->targetIds($targets);
        if (! in_array($id, $ids, true) || $delta === 0) {
            return;
        }

        $prefs = $this->normalized($user, $ids);
        $hidden = array_flip($prefs['hidden']);
        $visibleIndexes = [];

        foreach ($prefs['order'] as $index => $siteId) {
            if (! isset($hidden[$siteId])) {
                $visibleIndexes[] = $index;
            }
        }

        $position = null;
        foreach ($visibleIndexes as $slot => $orderIndex) {
            if ($prefs['order'][$orderIndex] === $id) {
                $position = $slot;
                break;
            }
        }

        if ($position === null) {
            return;
        }

        $swapWith = $position + $delta;
        if ($swapWith < 0 || $swapWith >= count($visibleIndexes)) {
            return;
        }

        $from = $visibleIndexes[$position];
        $to = $visibleIndexes[$swapWith];
        [$prefs['order'][$from], $prefs['order'][$to]] = [$prefs['order'][$to], $prefs['order'][$from]];

        $this->store($user, $prefs);
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     */
    public function hide(User $user, array $targets, int $id): void
    {
        $ids = $this->targetIds($targets);
        if (! in_array($id, $ids, true)) {
            return;
        }

        $prefs = $this->normalized($user, $ids);
        if (! in_array($id, $prefs['hidden'], true)) {
            $prefs['hidden'][] = $id;
        }

        $this->store($user, $prefs);
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     */
    public function show(User $user, array $targets, int $id): void
    {
        $ids = $this->targetIds($targets);
        $prefs = $this->normalized($user, $ids);
        $prefs['hidden'] = array_values(array_filter($prefs['hidden'], fn (int $hiddenId): bool => $hiddenId !== $id));
        $this->store($user, $prefs);
    }

    public function reset(User $user): void
    {
        $user->forceFill(['dashboard_table' => null])->save();
    }

    /**
     * @param  list<int>  $knownIds
     * @return array{order: list<int>, hidden: list<int>}
     */
    public function normalized(User $user, array $knownIds): array
    {
        $knownIds = array_values(array_unique(array_map('intval', $knownIds)));
        $stored = is_array($user->dashboard_table) ? $user->dashboard_table : [];
        $order = array_values(array_filter(
            array_map('intval', is_array($stored['order'] ?? null) ? $stored['order'] : []),
            fn (int $id): bool => in_array($id, $knownIds, true),
        ));

        foreach ($knownIds as $id) {
            if (! in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        $hidden = array_values(array_filter(
            array_map('intval', is_array($stored['hidden'] ?? null) ? $stored['hidden'] : []),
            fn (int $id): bool => in_array($id, $knownIds, true),
        ));

        return [
            'order' => $order,
            'hidden' => $hidden,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     * @return list<int>
     */
    private function targetIds(array $targets): array
    {
        return array_values(array_map(fn (array $target): int => (int) $target['id'], $targets));
    }

    /**
     * @param  array{order: list<int>, hidden: list<int>}  $prefs
     */
    private function store(User $user, array $prefs): void
    {
        $user->forceFill([
            'dashboard_table' => [
                'order' => array_values($prefs['order']),
                'hidden' => array_values($prefs['hidden']),
            ],
        ])->save();
    }
}
