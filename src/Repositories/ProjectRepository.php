<?php
declare(strict_types=1);

namespace AperturePro\Repositories;

class ProjectRepository
{
    public function find(int $id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ap_projects';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
        return $row ?: null;
    }

    public function update(int $id, array $data): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ap_projects';
        $wpdb->update($table, $data, ['id' => $id]);
    }
}
