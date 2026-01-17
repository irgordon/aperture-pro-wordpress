<?php

namespace AperturePro\Health;

/**
 * HealthCardRegistry
 *
 * Central registry for Health Dashboard cards.
 * Declares which cards exist, their properties, and handles visibility logic.
 */
class HealthCardRegistry
{
    /**
     * Get all registered health cards.
     *
     * @return array
     */
    public function getCards(): array
    {
        $cards = [
            [
                'id'            => 'performance',
                'label'         => 'Performance',
                'spa_component' => 'performance-card',
                'order'         => 10,
                'capability'    => 'manage_options',
                'enabled'       => true,
            ],
            [
                'id'            => 'storage',
                'label'         => 'Storage',
                'spa_component' => 'storage-card',
                'order'         => 20,
                'capability'    => 'manage_options',
                'enabled'       => true,
            ],
        ];

        usort($cards, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        return $cards;
    }
}
