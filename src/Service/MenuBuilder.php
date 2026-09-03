<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

class MenuBuilder
{
    public function buildMenu(User $user): array
    {
        $menu = [];
        
        $menu[] = [
            'label' => 'Dashboard',
            'route' => 'dashboard_index',
            'icon' => 'fa-home',
            'roles' => ['ROLE_USER'],
        ];
        
        if ($user->hasRole('ROLE_ADMIN', 'ROLE_MANAGER')) {
            $menu[] = [
                'label' => 'Rotas',
                'route' => 'admin_routes_index',
                'icon' => 'fa-route',
                'roles' => ['ROLE_ADMIN', 'ROLE_MANAGER'],
            ];
            
            $menu[] = [
                'label' => 'Alertas',
                'route' => 'admin_alerts_index',
                'icon' => 'fa-exclamation-triangle',
                'roles' => ['ROLE_ADMIN', 'ROLE_MANAGER'],
            ];
        }
        
        if ($user->hasRole('ROLE_PARTNER')) {
            $menu[] = [
                'label' => 'Minhas Rotas',
                'route' => 'partner_routes_index',
                'icon' => 'fa-map',
                'roles' => ['ROLE_PARTNER'],
            ];
        }
        
        if ($user->hasRole('ROLE_ADMIN')) {
            $menu[] = [
                'label' => 'Usu\u00e1rios',
                'route' => 'admin_users_index',
                'icon' => 'fa-users',
                'roles' => ['ROLE_ADMIN'],
            ];
            
            $menu[] = [
                'label' => 'Configura\u00e7\u00f5es',
                'route' => 'admin_settings_index',
                'icon' => 'fa-cog',
                'roles' => ['ROLE_ADMIN'],
            ];
        }
        
        return array_filter($menu, fn($item) => $user->hasRole(...$item['roles']));
    }
}
