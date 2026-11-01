<?php
declare(strict_types=1);
/** Regolamento della regia automatica, versione 1. Scelte di gioco esplicite, non dati del modello.
 * I manuali definiscono le stirpi; questa configurazione formalizza i confronti e i danni.
 */
return ['version'=>'alphano_scene_v1','turn_seconds'=>900,'support_bonus'=>4,'max_support'=>2,
 'ranged_max_range'=>30,'reload_rounds'=>1,'weapon_accuracy'=>6,'defence_bonus'=>4,'held_penalty'=>6,'wound_penalty_max'=>6,'damage_min'=>20,'damage_max'=>240,
 'unarmed_multiplier'=>2,'weapon_multiplier'=>3,'margin_multiplier'=>2,'armor_absorption'=>20,
 'reward_min_rounds'=>3,'reward_min_actions'=>2,'reward_daily_cap'=>100,'reward_cooldown'=>3600];
