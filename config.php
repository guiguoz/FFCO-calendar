<?php
/**
 * Configuration pour la synchronisation Vikazim2 (site vikazim.fr)
 */

return [
    // Départements à synchroniser
    'departments' => ['27', '76', '14', '50', '61'],

    // URL source FFCO : base des Courses CN (l'ancien agenda
    // /courses/agenda/ ne sert plus de tableau depuis la refonte du site)
    'ffc_url' => 'https://cn.ffcorientation.fr/course/',

    // Mapping villes -> départements (à compléter selon vos besoins)
    'city_department_map' => [
        // Seine-Maritime (76)
        'rouen' => '76', 'le havre' => '76', 'dieppe' => '76', 'fecamp' => '76',
        'elbeuf' => '76', 'sotteville' => '76', 'mont-saint-aignan' => '76',

        // Calvados (14)
        'caen' => '14', 'bayeux' => '14', 'lisieux' => '14', 'vire' => '14',
        'falaise' => '14', 'honfleur' => '14', 'cabourg' => '14',

        // Manche (50)
        'cherbourg' => '50', 'saint-lo' => '50', 'avranches' => '50', 'coutances' => '50',
        'granville' => '50', 'valognes' => '50',

        // Eure (27)
        'evreux' => '27', 'vernon' => '27', 'louviers' => '27', 'pont-audemer' => '27',
        'bernay' => '27', 'gisors' => '27',

        // Orne (61)
        'alencon' => '61', 'flers' => '61', 'argentan' => '61', 'mortagne-au-perche' => '61',
        'la ferte-mace' => '61', 'sees' => '61'
    ],

    // Options The Events Calendar côté vikazim.fr
    'mec_options' => [
        'default_status' => 'publish',
        'event_category' => 'course-orientation',
        'organizer_name' => 'Vikazim'
    ],

    // Fenêtre de scan
    'scan_window' => [
        'mode' => 'current_and_next_month',
        'du_offset_days' => 0,
        'scan_months_ahead' => 3,
        'max_pages' => 20,
        'ignore_past' => true,
        'months_ahead_limit' => 12
    ]
];


