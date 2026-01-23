<?php
declare(strict_types=1);

namespace AperturePro\Repositories;

class ProjectRepository
{
    public function find(int $id)
    {
        $cached = wp_cache_get($id, 'ap_projects');
        if (false !== $cached) {
            return $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ap_projects';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
        $result = $row ?: null;

        wp_cache_set($id, $result, 'ap_projects');

        return $result;
    }

    public function update(int $id, array $data): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ap_projects';
        $wpdb->update($table, $data, ['id' => $id]);
        wp_cache_delete($id, 'ap_projects');
    }

    public function get_images_for_project(int $project_id): array
    {
        global $wpdb;
        $images_table = $wpdb->prefix . 'ap_images';
        $galleries_table = $wpdb->prefix . 'ap_galleries';

        $sql = $wpdb->prepare(
            "SELECT
                i.id,
                i.storage_key_original AS path,
                SUBSTRING_INDEX(i.storage_key_original, '/', -1) AS filename,
                i.is_selected,
                i.client_comments
            FROM
                {$images_table} i
            JOIN
                {$galleries_table} g ON i.gallery_id = g.id
            WHERE
                g.project_id = %d
            ORDER BY
                i.sort_order ASC, i.id ASC
            ",
            $project_id
        );

        $results = $wpdb->get_results($sql, ARRAY_A);

        if (empty($results)) {
            return [];
        }

        return array_map(function ($row) use ($project_id) {
            $comments = json_decode($row['client_comments'] ?? '[]', true);
            return [
                'id' => (int) $row['id'],
                'project_id' => $project_id,
                'path' => $row['path'],
                'filename' => $row['filename'],
                'is_selected' => (bool) $row['is_selected'],
                'comments' => is_array($comments) ? $comments : [],
            ];
        }, $results);
    }
}
