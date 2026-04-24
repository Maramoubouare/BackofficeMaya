<?php
// src/Twig/SettingsExtension.php

namespace App\Twig;

use App\Repository\SettingsRepository;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class SettingsExtension extends AbstractExtension implements GlobalsInterface
{
    private SettingsRepository $settingsRepository;

    public function __construct(SettingsRepository $settingsRepository)
    {
        $this->settingsRepository = $settingsRepository;
    }

    public function getGlobals(): array
    {
        $settings = [];
        
        try {
            $allSettings = $this->settingsRepository->findAll();
            
            foreach ($allSettings as $setting) {
                $settings[$setting->getSettingKey()] = $setting->getSettingValue();
            }
        } catch (\Exception $e) {
            // Si la table n'existe pas encore, utiliser valeurs par défaut
            $settings = [
                'primary_color' => '#14B53A',
                'secondary_color' => '#0e8c2d',
                'sidebar_color' => '#2c3e50',
                'background_color' => '#f5f7fa',
                'card_color' => '#ffffff',
                'text_color' => '#2c3e50',
                'company_name' => 'Maya',
                'company_slogan' => 'BilletExpress',
                'logo_url' => ''
            ];
        }

        return [
            'app_settings' => $settings
        ];
    }
}