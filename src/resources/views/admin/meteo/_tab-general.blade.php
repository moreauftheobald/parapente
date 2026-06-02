{{--
    Onglet Général — Paramètres de fiabilité des modèles météo.
    Inclus depuis admin/meteo/settings.blade.php quand $tab === 'general'.
    Attend $settingsGroups passé par SectionSettingsController::meteo().
--}}
@include('admin.settings._groups-form', [
    'settingsGroups' => $settingsGroups,
    'saveAction'     => route('admin.meteo.settings.general'),
])
