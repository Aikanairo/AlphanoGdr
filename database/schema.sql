-- AlphanoGdr: schema without game data, accounts, logs or installation counters.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
CREATE TABLE `accademia` (
  `idpg` int(10) NOT NULL DEFAULT 0,
  `org` int(11) NOT NULL DEFAULT 0,
  `corp` int(11) NOT NULL DEFAULT 0,
  `obj` int(11) NOT NULL DEFAULT 0,
  `giocata` int(11) NOT NULL DEFAULT 0,
  `voto` varchar(4) DEFAULT NULL,
  `motivo` mediumtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `activity_event_audiences` (
  `event_id` bigint(20) unsigned NOT NULL,
  `audience_scope` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope_id` int(11) NOT NULL,
  `section_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `access_role` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`event_id`,`audience_scope`,`scope_id`,`section_key`,`access_role`),
  KEY `idx_activity_audience` (`audience_scope`,`scope_id`,`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `activity_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_key` varchar(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_id` int(11) NOT NULL,
  `actor_name` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `detail` text NOT NULL,
  `data_json` mediumtext NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_activity_event` (`event_key`),
  KEY `idx_activity_created` (`created_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `activity_notification_reads` (
  `recipient` int(11) NOT NULL,
  `notice_key` varchar(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `read_at` bigint(20) NOT NULL,
  PRIMARY KEY (`recipient`,`notice_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `ai_companion_seed` (
  `slug` varchar(80) NOT NULL,
  `npc_id` bigint(20) unsigned NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`slug`),
  UNIQUE KEY `npc_id` (`npc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_demo_limits` (
  `scope` char(64) NOT NULL,
  `window_start` bigint(20) NOT NULL,
  `hits` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_demo_turns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `visit_id` char(64) NOT NULL,
  `chat_key` varchar(100) NOT NULL,
  `npc_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `request_key` varchar(80) NOT NULL,
  `prompt` text NOT NULL,
  `result` text DEFAULT NULL,
  `job_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `retry_once` (`visit_id`,`request_key`),
  KEY `history` (`visit_id`,`chat_key`,`id`),
  KEY `job` (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_demo_visits` (
  `id` char(64) NOT NULL,
  `name` varchar(40) NOT NULL,
  `sex` varchar(12) NOT NULL,
  `ip_hash` char(64) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `expires_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `expiry` (`expires_at`),
  KEY `ip_rate` (`ip_hash`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chat_key` varchar(100) NOT NULL,
  `npc_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `source_id` bigint(20) DEFAULT NULL,
  `job_id` bigint(20) unsigned DEFAULT NULL,
  `speaker` varchar(100) NOT NULL,
  `content` text NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `source_once` (`chat_key`,`source_id`),
  UNIQUE KEY `job_once` (`job_id`),
  KEY `room_history` (`chat_key`,`id`),
  KEY `npc_history` (`chat_key`,`npc_id`,`id`),
  FULLTEXT KEY `event_search` (`content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_identity_observations` (
  `npc_id` bigint(20) unsigned NOT NULL,
  `player_id` bigint(20) unsigned NOT NULL,
  `chat_key` varchar(100) NOT NULL,
  `source_id` bigint(20) unsigned NOT NULL,
  `face_hidden` tinyint(4) NOT NULL,
  `identified` tinyint(4) NOT NULL,
  `scene_start` bigint(20) unsigned NOT NULL DEFAULT 0,
  `reason` varchar(250) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`npc_id`,`source_id`),
  KEY `identity_pair` (`npc_id`,`player_id`,`chat_key`,`source_id`),
  KEY `identity_player` (`player_id`,`source_id`),
  KEY `identity_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chat_key` varchar(100) NOT NULL,
  `npc_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `request_key` varchar(150) NOT NULL,
  `kind` varchar(12) NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'queued',
  `prompt` text NOT NULL,
  `room_version` int(11) NOT NULL,
  `source_id` bigint(20) NOT NULL DEFAULT 0,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `available_at` bigint(20) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `started_at` bigint(20) NOT NULL DEFAULT 0,
  `finished_at` bigint(20) NOT NULL DEFAULT 0,
  `result` text DEFAULT NULL,
  `error` varchar(255) DEFAULT NULL,
  `message_id` bigint(20) NOT NULL DEFAULT 0,
  `session_id` bigint(20) NOT NULL DEFAULT 0,
  `log_synced` tinyint(4) NOT NULL DEFAULT 0,
  `game_snapshot` char(64) DEFAULT NULL,
  `dispatch_order` bigint(20) unsigned NOT NULL DEFAULT 0,
  `worker_token` varchar(32) NOT NULL DEFAULT '',
  `lease_until` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_once` (`request_key`),
  KEY `next_job` (`status`,`available_at`,`id`),
  KEY `room_pending` (`chat_key`,`status`),
  KEY `user_rate` (`user_id`,`created_at`),
  KEY `room_dispatch` (`chat_key`,`dispatch_order`),
  KEY `player_dispatch` (`user_id`,`dispatch_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_master_knowledge` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(160) NOT NULL,
  `topic` varchar(80) NOT NULL,
  `origin` varchar(20) NOT NULL,
  `source` varchar(512) NOT NULL,
  `summary` text NOT NULL,
  `mechanics` text NOT NULL,
  `active` tinyint(4) NOT NULL DEFAULT 1,
  `version` int(11) NOT NULL DEFAULT 1,
  `updated_by` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `topics` (`active`,`topic`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_memory` (
  `chat_key` varchar(100) NOT NULL,
  `npc_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `summary` text NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `canonical` text NOT NULL DEFAULT (''),
  PRIMARY KEY (`chat_key`,`npc_id`),
  CONSTRAINT `ai_memory_ibfk_1` FOREIGN KEY (`chat_key`) REFERENCES `ai_rooms` (`chat_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_nature_knowledge` (
  `npc_id` bigint(20) unsigned NOT NULL,
  `player_id` bigint(20) NOT NULL,
  `race_id` int(11) NOT NULL,
  `source_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `chat_key` varchar(100) NOT NULL,
  `evidence` varchar(500) NOT NULL,
  `first_seen` bigint(20) NOT NULL,
  `last_seen` bigint(20) NOT NULL,
  PRIMARY KEY (`npc_id`,`player_id`),
  KEY `player` (`player_id`,`npc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_nature_observations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `npc_id` bigint(20) unsigned NOT NULL,
  `player_id` bigint(20) NOT NULL,
  `race_id` int(11) NOT NULL,
  `source_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `chat_key` varchar(100) NOT NULL,
  `evidence` varchar(500) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt` (`npc_id`,`player_id`,`source_id`,`race_id`),
  KEY `recall` (`npc_id`,`player_id`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_npc_adult` (
  `npc_id` bigint(20) unsigned NOT NULL,
  `sexuality_enabled` tinyint(4) NOT NULL DEFAULT 0,
  `companion_enabled` tinyint(4) NOT NULL DEFAULT 0,
  `workplace_key` varchar(100) DEFAULT NULL,
  `exclusive_player_id` int(11) NOT NULL DEFAULT 0,
  `updated_at` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`npc_id`),
  KEY `workplace` (`workplace_key`,`companion_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_npc_debts` (
  `npc_id` bigint(20) unsigned NOT NULL,
  `player_id` bigint(20) NOT NULL,
  `player_owes` bigint(20) NOT NULL DEFAULT 0,
  `npc_owes` bigint(20) NOT NULL DEFAULT 0,
  `updated_at` bigint(20) NOT NULL,
  PRIMARY KEY (`npc_id`,`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_npc_game` (
  `npc_id` bigint(20) unsigned NOT NULL,
  `settings` mediumtext NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `updated_by` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  PRIMARY KEY (`npc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_npc_offers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `npc_id` bigint(20) unsigned NOT NULL,
  `player_id` bigint(20) NOT NULL,
  `kind` varchar(25) NOT NULL,
  `amount` bigint(20) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `request_key` varchar(150) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `expires_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `offer_once` (`request_key`),
  KEY `personal_offers` (`npc_id`,`player_id`,`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_npc_rules` (
  `npc_id` bigint(20) unsigned NOT NULL,
  `settings` text NOT NULL,
  `updated_by` bigint(20) NOT NULL DEFAULT 0,
  `updated_at` bigint(20) NOT NULL,
  PRIMARY KEY (`npc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_npc_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `npc_id` bigint(20) unsigned NOT NULL,
  `player_id` bigint(20) NOT NULL,
  `offer_id` bigint(20) unsigned DEFAULT NULL,
  `kind` varchar(25) NOT NULL,
  `amount` bigint(20) NOT NULL,
  `player_delta` bigint(20) NOT NULL,
  `npc_before` bigint(20) NOT NULL,
  `npc_after` bigint(20) NOT NULL,
  `player_before` bigint(20) NOT NULL,
  `player_after` bigint(20) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `request_key` varchar(150) NOT NULL,
  `actor_id` bigint(20) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `once_key` (`request_key`),
  UNIQUE KEY `once_offer` (`offer_id`),
  KEY `account_history` (`npc_id`,`player_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_npc_wallets` (
  `npc_id` bigint(20) unsigned NOT NULL,
  `balance` bigint(20) NOT NULL DEFAULT 0,
  `updated_at` bigint(20) NOT NULL,
  PRIMARY KEY (`npc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_npcs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chat_key` varchar(100) NOT NULL,
  `name` varchar(80) NOT NULL,
  `active` tinyint(4) NOT NULL DEFAULT 0,
  `character_text` text NOT NULL,
  `personality` text NOT NULL,
  `history` text NOT NULL,
  `knowledge` text NOT NULL,
  `behavior` text NOT NULL,
  `profile` text NOT NULL DEFAULT ('{}'),
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_name` (`chat_key`,`name`),
  KEY `room_active` (`chat_key`,`active`),
  CONSTRAINT `ai_npcs_ibfk_1` FOREIGN KEY (`chat_key`) REFERENCES `ai_rooms` (`chat_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_personal_memories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `npc_id` bigint(20) unsigned NOT NULL,
  `player_id` bigint(20) NOT NULL,
  `chat_key` varchar(100) NOT NULL,
  `job_id` bigint(20) unsigned DEFAULT NULL,
  `category` varchar(30) NOT NULL,
  `summary` text NOT NULL,
  `evidence` text NOT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'dialogue',
  `visible` tinyint(4) NOT NULL DEFAULT 1,
  `active` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `epistemic` varchar(20) NOT NULL DEFAULT 'dialogue',
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_memory` (`job_id`,`category`),
  KEY `recall` (`npc_id`,`player_id`,`active`,`id`),
  KEY `npc_chronicle` (`npc_id`,`id`),
  FULLTEXT KEY `memory_search` (`summary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_player_preferences` (
  `player_id` bigint(20) NOT NULL,
  `character_age` int(11) NOT NULL DEFAULT 0,
  `romance_enabled` tinyint(4) NOT NULL DEFAULT 0,
  `updated_at` bigint(20) NOT NULL,
  `intimacy_enabled` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_quest_actions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `round_number` int(11) NOT NULL,
  `kind` varchar(3) NOT NULL,
  `actor_id` bigint(20) NOT NULL,
  `source_id` bigint(20) NOT NULL DEFAULT 0,
  `content` text NOT NULL,
  `details` text NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'submitted',
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `actor_turn` (`session_id`,`round_number`,`kind`,`actor_id`),
  KEY `source_event` (`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_quest_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `actor_id` bigint(20) NOT NULL DEFAULT 0,
  `event_type` varchar(40) NOT NULL,
  `payload` mediumtext NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `history` (`session_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_quest_cast` (
  `session_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(3) NOT NULL,
  `actor_id` bigint(20) NOT NULL,
  `active` tinyint(4) NOT NULL DEFAULT 0,
  `state` text NOT NULL,
  PRIMARY KEY (`session_id`,`kind`,`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_quest_effects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `verdict_id` bigint(20) unsigned NOT NULL,
  `effect_key` varchar(120) NOT NULL,
  `effect_type` varchar(30) NOT NULL,
  `target_kind` varchar(3) NOT NULL,
  `target_id` bigint(20) NOT NULL,
  `before_state` mediumtext NOT NULL,
  `after_state` mediumtext NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'applied',
  `actor_id` bigint(20) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `effect_key` (`effect_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_quest_outbox` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `event_key` varchar(120) NOT NULL,
  `html` mediumtext NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `chat_id` bigint(20) NOT NULL,
  `log_synced` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_key` (`event_key`),
  KEY `scene_message_lookup` (`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_quest_settlements` (
  `session_id` bigint(20) unsigned NOT NULL,
  `player_id` bigint(20) NOT NULL,
  `amount` int(11) NOT NULL DEFAULT 0,
  `note` text NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`session_id`,`player_id`),
  KEY `daily` (`player_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_quest_verdicts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `round_number` int(11) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'review',
  `snapshot` char(64) NOT NULL,
  `payload` mediumtext NOT NULL,
  `technical` text NOT NULL,
  `narrative` text NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `reviewed_by` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `round_once` (`session_id`,`round_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_quests` (
  `session_id` bigint(20) unsigned NOT NULL,
  `chat_key` varchar(100) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `mode` varchar(12) NOT NULL DEFAULT 'auto',
  `policy` text NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `created_at` bigint(20) NOT NULL,
  `conductor_id` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`session_id`),
  KEY `room` (`chat_key`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_relationship_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `npc_id` bigint(20) unsigned NOT NULL,
  `player_id` bigint(20) NOT NULL,
  `job_id` bigint(20) unsigned DEFAULT NULL,
  `before_state` text NOT NULL,
  `after_state` text NOT NULL,
  `reason` text NOT NULL,
  `actor_id` bigint(20) NOT NULL DEFAULT 0,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_once` (`job_id`),
  KEY `pair_events` (`npc_id`,`player_id`,`id`),
  KEY `pair_recent` (`npc_id`,`player_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_relationships` (
  `npc_id` bigint(20) unsigned NOT NULL,
  `player_id` bigint(20) NOT NULL,
  `sympathy` int(11) NOT NULL DEFAULT 0,
  `trust` int(11) NOT NULL DEFAULT 0,
  `fear` int(11) NOT NULL DEFAULT 0,
  `respect` int(11) NOT NULL DEFAULT 0,
  `honor` int(11) NOT NULL DEFAULT 0,
  `affection` int(11) NOT NULL DEFAULT 0,
  `attraction` int(11) NOT NULL DEFAULT 0,
  `love` int(11) NOT NULL DEFAULT 0,
  `jealousy` int(11) NOT NULL DEFAULT 0,
  `state` varchar(30) NOT NULL DEFAULT 'conoscenza',
  `encounters` int(11) NOT NULL DEFAULT 0,
  `meaningful` int(11) NOT NULL DEFAULT 0,
  `revealed` text NOT NULL DEFAULT ('[]'),
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `last_encounter` bigint(20) NOT NULL DEFAULT 0,
  `known_name` varchar(100) NOT NULL DEFAULT '',
  `interactions` int(11) NOT NULL DEFAULT 0,
  `hostility` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`npc_id`,`player_id`),
  KEY `npc_activity` (`npc_id`,`last_encounter`,`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_rooms` (
  `chat_key` varchar(100) NOT NULL,
  `enabled` tinyint(4) NOT NULL DEFAULT 0,
  `mode` varchar(12) NOT NULL DEFAULT 'request',
  `setting` text NOT NULL,
  `atmosphere` text NOT NULL,
  `plot` text NOT NULL,
  `style` text NOT NULL,
  `response_length` varchar(12) NOT NULL DEFAULT 'medium',
  `cooldown` int(11) NOT NULL DEFAULT 30,
  `trigger_every` int(11) NOT NULL DEFAULT 3,
  `turn_count` int(11) NOT NULL DEFAULT 0,
  `last_enqueued` bigint(20) NOT NULL DEFAULT 0,
  `version` int(11) NOT NULL DEFAULT 1,
  `updated_by` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `public_setting` text NOT NULL DEFAULT (''),
  `quest_auto` tinyint(4) NOT NULL DEFAULT 1,
  `quest_auto_close` tinyint(4) NOT NULL DEFAULT 1,
  `quest_auto_continue` tinyint(4) NOT NULL DEFAULT 1,
  `quest_rules` varchar(30) NOT NULL DEFAULT 'legacy',
  `quest_lethal` tinyint(4) NOT NULL DEFAULT 0,
  `quest_reward` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`chat_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_scene_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_id` bigint(20) unsigned NOT NULL,
  `chat_key` varchar(100) NOT NULL,
  `kind` varchar(30) NOT NULL,
  `actor_id` bigint(20) NOT NULL DEFAULT 0,
  `master_id` bigint(20) NOT NULL DEFAULT 0,
  `native_race_id` int(11) NOT NULL DEFAULT 0,
  `visible_race_id` int(11) NOT NULL DEFAULT 0,
  `form` varchar(12) NOT NULL DEFAULT '',
  `content` text NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `source_id` (`source_id`),
  KEY `scene` (`chat_key`,`id`),
  KEY `actor_metamorphosis` (`chat_key`,`kind`,`actor_id`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_scene_witnesses` (
  `event_id` bigint(20) unsigned NOT NULL,
  `npc_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`event_id`,`npc_id`),
  KEY `recall` (`npc_id`,`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_session_guidance` (
  `session_id` bigint(20) unsigned NOT NULL,
  `notes` text NOT NULL,
  `phase` varchar(20) NOT NULL DEFAULT 'narrazione',
  `paused` tinyint(4) NOT NULL DEFAULT 0,
  `turn_order` text NOT NULL,
  `turn_index` int(11) NOT NULL DEFAULT 0,
  `round_number` int(11) NOT NULL DEFAULT 1,
  `version` int(11) NOT NULL DEFAULT 1,
  `updated_by` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `turn_started_at` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_social_jobs` (
  `job_id` bigint(20) unsigned NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'queued',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `started_at` bigint(20) NOT NULL DEFAULT 0,
  `available_at` bigint(20) NOT NULL DEFAULT 0,
  `error` varchar(255) NOT NULL DEFAULT '',
  `proposal` text DEFAULT NULL,
  `observation` text DEFAULT NULL,
  PRIMARY KEY (`job_id`),
  KEY `next_social` (`status`,`available_at`,`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_worker` (
  `id` int(11) NOT NULL,
  `heartbeat` bigint(20) NOT NULL DEFAULT 0,
  `state` varchar(40) NOT NULL DEFAULT 'starting',
  `last_error` varchar(255) NOT NULL DEFAULT '',
  `dispatch_counter` bigint(20) unsigned NOT NULL DEFAULT 0,
  `social_dispatch` bigint(20) unsigned NOT NULL DEFAULT 0,
  `social_token` varchar(32) NOT NULL DEFAULT '',
  `social_lease` bigint(20) NOT NULL DEFAULT 0,
  `pool_slots` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ai_world_updates` (
  `update_key` varchar(100) NOT NULL,
  `applied_at` bigint(20) NOT NULL,
  `details` text NOT NULL,
  PRIMARY KEY (`update_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `araldiche` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) NOT NULL DEFAULT '',
  `prima` char(1) NOT NULL DEFAULT '0',
  `city` int(11) NOT NULL DEFAULT 0,
  `ramo` int(11) NOT NULL DEFAULT 0,
  `stipendio` int(11) NOT NULL DEFAULT 0,
  `descrizione` mediumtext NOT NULL,
  `livello` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `armadio` (
  `id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `uid` bigint(20) unsigned NOT NULL DEFAULT 0,
  `idpg` int(10) NOT NULL DEFAULT 0,
  `slot` varchar(255) NOT NULL DEFAULT '',
  `nome` varchar(255) NOT NULL DEFAULT '',
  `consumo` int(11) NOT NULL DEFAULT 0,
  `idpadre` int(11) NOT NULL DEFAULT 0,
  `vetrina` char(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `assemblea` (
  `city` int(11) NOT NULL DEFAULT 0,
  `pg` int(11) NOT NULL DEFAULT 0,
  `voto` char(1) NOT NULL DEFAULT '0',
  KEY `groups_city_voter` (`city`,`pg`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `avvisi` (
  `chat` varchar(50) DEFAULT NULL,
  `testo` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `bacheche` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `replyid` int(10) NOT NULL DEFAULT 0,
  `data` int(10) NOT NULL DEFAULT 0,
  `oggetto` varchar(255) NOT NULL DEFAULT '',
  `messaggio` longtext NOT NULL,
  `idpg` int(10) NOT NULL DEFAULT 0,
  `segnalazione` char(1) NOT NULL DEFAULT '0',
  `bacheca` int(11) NOT NULL DEFAULT 0,
  `visite` int(11) NOT NULL DEFAULT 0,
  `chiusa` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `manuals_board_roots` (`bacheca`,`replyid`,`id`),
  KEY `manuals_board_last` (`bacheca`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `board_game_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `game_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `actor_id` int(11) NOT NULL,
  `action` varchar(32) NOT NULL,
  `details_json` mediumtext NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `game_log` (`game_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `board_game_escrow` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `game_id` bigint(20) unsigned NOT NULL,
  `owner_id` int(11) NOT NULL,
  `asset_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `coins` int(11) NOT NULL DEFAULT 0,
  `released_to` int(11) NOT NULL DEFAULT 0,
  `released_at` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `match_assets` (`game_id`,`released_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `board_game_interactions` (
  `chat_key` varchar(100) NOT NULL,
  `actor_id` int(11) NOT NULL,
  `target_id` int(11) NOT NULL,
  `source_id` bigint(20) unsigned NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`source_id`,`actor_id`,`target_id`),
  KEY `spectators` (`chat_key`,`actor_id`,`target_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `board_game_ratings` (
  `player_id` int(11) NOT NULL,
  `game_key` varchar(12) NOT NULL,
  `elo` int(11) NOT NULL DEFAULT 1200,
  `wins` int(11) NOT NULL DEFAULT 0,
  `losses` int(11) NOT NULL DEFAULT 0,
  `draws` int(11) NOT NULL DEFAULT 0,
  `games` int(11) NOT NULL DEFAULT 0,
  `intelligence` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`player_id`,`game_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `board_game_rewards` (
  `game_id` bigint(20) unsigned NOT NULL,
  `player_id` int(11) NOT NULL,
  `opponent_id` int(11) NOT NULL,
  `points` int(11) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`game_id`,`player_id`),
  KEY `day_rewards` (`player_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `board_game_rooms` (
  `chat_key` varchar(100) NOT NULL,
  `scacchi` tinyint(4) NOT NULL DEFAULT 0,
  `dama` tinyint(4) NOT NULL DEFAULT 0,
  `coins` tinyint(4) NOT NULL DEFAULT 0,
  `items` tinyint(4) NOT NULL DEFAULT 0,
  `materials` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`chat_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `board_game_settings` (
  `id` int(11) NOT NULL,
  `settings_json` text NOT NULL,
  `revision` int(11) NOT NULL DEFAULT 1,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `board_games` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `game_key` varchar(12) NOT NULL,
  `chat_key` varchar(100) NOT NULL,
  `a` int(11) NOT NULL,
  `b` int(11) NOT NULL,
  `proposer` int(11) NOT NULL,
  `status` varchar(16) NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `terms_json` text NOT NULL,
  `state_json` mediumtext NOT NULL,
  `moves_json` mediumtext NOT NULL,
  `white_id` int(11) NOT NULL DEFAULT 0,
  `clock_white` bigint(20) NOT NULL DEFAULT 0,
  `clock_black` bigint(20) NOT NULL DEFAULT 0,
  `turn_at` bigint(20) NOT NULL DEFAULT 0,
  `created_at` bigint(20) NOT NULL,
  `started_at` bigint(20) NOT NULL DEFAULT 0,
  `finished_at` bigint(20) NOT NULL DEFAULT 0,
  `winner` int(11) NOT NULL DEFAULT 0,
  `reason` varchar(300) NOT NULL DEFAULT '',
  `active_seconds` int(11) NOT NULL DEFAULT 0,
  `draw_by` int(11) NOT NULL DEFAULT 0,
  `result_json` text NOT NULL,
  `a_net` char(64) NOT NULL,
  `b_net` char(64) NOT NULL DEFAULT '',
  `request_key` varchar(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_once` (`a`,`request_key`),
  KEY `room_games` (`chat_key`,`status`,`id`),
  KEY `player_a` (`a`,`status`),
  KEY `player_b` (`b`,`status`),
  KEY `ended` (`finished_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `bug` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idpg` int(11) NOT NULL DEFAULT 0,
  `email` varchar(255) NOT NULL DEFAULT '',
  `data` int(10) NOT NULL DEFAULT 0,
  `attivato` char(1) NOT NULL DEFAULT '0',
  `sezione` int(11) NOT NULL DEFAULT 0,
  `descrizione` mediumtext NOT NULL,
  `stato` int(11) NOT NULL DEFAULT 0,
  `admin` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `candidati` (
  `idpg` int(11) NOT NULL DEFAULT 0,
  `organizzazione` int(11) NOT NULL DEFAULT 0,
  `city` int(11) NOT NULL DEFAULT 0,
  `programma` mediumtext NOT NULL,
  PRIMARY KEY (`idpg`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `carichecorporazioni` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `corporazione` int(10) NOT NULL DEFAULT 0,
  `nome` varchar(50) NOT NULL DEFAULT '',
  `prima` char(1) NOT NULL DEFAULT '0',
  `ramo` int(11) NOT NULL DEFAULT 0,
  `livello` int(11) NOT NULL DEFAULT 0,
  `descrizione` mediumtext NOT NULL,
  `stipendio` bigint(20) NOT NULL DEFAULT 0,
  `provvigione` float NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `caricheorganizzazioni` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `organizzazione` int(10) NOT NULL DEFAULT 0,
  `nome` varchar(50) NOT NULL DEFAULT '',
  `prima` char(1) NOT NULL DEFAULT '0',
  `ramo` int(11) NOT NULL DEFAULT 0,
  `livello` int(11) NOT NULL DEFAULT 0,
  `descrizione` mediumtext NOT NULL,
  `stipendio` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `chat` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pg` bigint(20) NOT NULL DEFAULT 0,
  `chat` varchar(50) NOT NULL DEFAULT '',
  `ora` int(10) NOT NULL DEFAULT 0,
  `msg` longtext NOT NULL,
  `dest` bigint(20) NOT NULL DEFAULT 0,
  `location` varchar(255) DEFAULT NULL,
  `lican` char(1) NOT NULL DEFAULT '0',
  `history_only` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_chat_room_id` (`chat`,`id`),
  KEY `chat_live_archive` (`chat`,`history_only`,`id`),
  KEY `chat_player_public` (`pg`,`dest`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `chat_dice_rolls` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `chat_key` varchar(100) NOT NULL,
  `session_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `round_number` int(11) NOT NULL DEFAULT 0,
  `request_key` varchar(80) NOT NULL,
  `result` int(11) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt` (`user_id`,`request_key`),
  KEY `scene` (`session_id`,`round_number`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `chat_rental_limits` (
  `player_id` int(11) NOT NULL,
  `window_start` bigint(20) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `chat_rental_members` (
  `rental_id` bigint(20) unsigned NOT NULL,
  `player_id` int(11) NOT NULL,
  `joined_at` bigint(20) NOT NULL,
  PRIMARY KEY (`rental_id`,`player_id`),
  KEY `player_rooms` (`player_id`,`rental_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `chat_rental_settings` (
  `id` int(11) NOT NULL,
  `lookup_secret` char(64) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `chat_rentals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chat_key` varchar(100) NOT NULL,
  `host_key` varchar(100) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `word_hash` varchar(255) NOT NULL,
  `word_lookup` char(64) DEFAULT NULL,
  `hours` int(11) NOT NULL,
  `hourly_cost` int(11) NOT NULL,
  `total_cost` int(11) NOT NULL,
  `started_at` bigint(20) NOT NULL,
  `expires_at` bigint(20) NOT NULL,
  `closed_at` bigint(20) NOT NULL DEFAULT 0,
  `request_key` varchar(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_key` (`chat_key`),
  UNIQUE KEY `receipt` (`owner_id`,`request_key`),
  UNIQUE KEY `room_word` (`host_key`,`word_lookup`),
  KEY `expiry` (`closed_at`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE `chat_work_admin_audit` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `actor_id` int(11) NOT NULL,
  `kind` varchar(20) NOT NULL,
  `target_id` int(11) NOT NULL DEFAULT 0,
  `before_state` text NOT NULL,
  `after_state` text NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `chat_work_attempts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `player_id` int(11) NOT NULL,
  `chat_key` varchar(80) NOT NULL,
  `job_id` int(11) NOT NULL,
  `request_key` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `action_chat_id` bigint(20) NOT NULL DEFAULT 0,
  `outcome_chat_id` bigint(20) NOT NULL DEFAULT 0,
  `roll` int(11) NOT NULL,
  `bonus` int(11) NOT NULL,
  `difficulty` int(11) NOT NULL,
  `probability` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `resource_id` bigint(20) NOT NULL,
  `snapshot` text NOT NULL,
  `inventory_ids` text NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `narration_chat_id` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt` (`player_id`,`request_key`),
  KEY `quota` (`player_id`,`created_at`),
  KEY `history` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `chat_work_options` (
  `job_id` int(11) NOT NULL,
  `enabled` tinyint(4) NOT NULL DEFAULT 1,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `stat_key` varchar(20) NOT NULL DEFAULT 'perception',
  PRIMARY KEY (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `chat_work_players` (
  `player_id` int(11) NOT NULL,
  `pending_attempt` bigint(20) NOT NULL DEFAULT 0,
  `rest_until` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `chat_work_settings` (
  `id` int(11) NOT NULL,
  `settings` text NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `chatpng` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mappa_id` int(11) NOT NULL,
  `chat_key` varchar(100) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `descrizione` text NOT NULL,
  `prompt_base` text NOT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `sesso` enum('M','F','N') NOT NULL DEFAULT 'N',
  `image_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_mappa` (`mappa_id`),
  CONSTRAINT `fk_chatpng_mappe` FOREIGN KEY (`mappa_id`) REFERENCES `mappe` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `city` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `city` varchar(255) NOT NULL DEFAULT '',
  `elezioni` char(1) NOT NULL DEFAULT '0',
  `ballottaggio` char(1) NOT NULL DEFAULT '0',
  `organizzazione` int(11) NOT NULL DEFAULT 0,
  `budget` int(11) NOT NULL DEFAULT 25000,
  `costopvt` int(11) NOT NULL DEFAULT 0,
  `costoorg` int(11) NOT NULL DEFAULT 0,
  `costocorp` int(11) NOT NULL DEFAULT 0,
  `costoelezioni` int(11) NOT NULL DEFAULT 0,
  `electiontime` int(11) NOT NULL DEFAULT 3,
  `ultimostipendio` int(10) NOT NULL DEFAULT 0,
  `statuto` mediumtext NOT NULL,
  `newstatuto` mediumtext NOT NULL,
  `prigione` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `civic_election_candidates` (
  `round_id` bigint(20) unsigned NOT NULL,
  `player_id` int(11) NOT NULL,
  `organization_id` int(11) NOT NULL DEFAULT 0,
  `program` mediumtext NOT NULL,
  `eligible` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  PRIMARY KEY (`round_id`,`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `civic_election_deliveries` (
  `event_id` bigint(20) unsigned NOT NULL,
  `player_id` int(11) NOT NULL,
  `mail_id` bigint(20) NOT NULL DEFAULT 0,
  `email_status` varchar(16) NOT NULL DEFAULT 'pending',
  `email_attempts` int(11) NOT NULL DEFAULT 0,
  `retry_at` bigint(20) NOT NULL DEFAULT 0,
  `email_error` varchar(250) NOT NULL DEFAULT '',
  PRIMARY KEY (`event_id`,`player_id`),
  KEY `pending_mail` (`email_status`,`retry_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `civic_election_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_key` varchar(100) NOT NULL,
  `city_id` int(11) NOT NULL,
  `round_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_key` (`event_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `civic_election_prompt_receipts` (
  `player_id` int(11) NOT NULL,
  `city_id` int(11) NOT NULL,
  `seen_at` bigint(20) NOT NULL,
  PRIMARY KEY (`player_id`,`city_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `civic_election_votes` (
  `round_id` bigint(20) unsigned NOT NULL,
  `ballot` int(11) NOT NULL,
  `voter_id` int(11) NOT NULL,
  `candidate_id` int(11) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`round_id`,`ballot`,`voter_id`),
  KEY `candidate_votes` (`round_id`,`ballot`,`candidate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `companion_appointments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `npc_id` bigint(20) unsigned NOT NULL,
  `player_id` int(11) NOT NULL,
  `rental_id` bigint(20) unsigned NOT NULL,
  `host_key` varchar(100) NOT NULL,
  `started_at` bigint(20) NOT NULL,
  `expires_at` bigint(20) NOT NULL,
  `finished_at` bigint(20) NOT NULL DEFAULT 0,
  `request_key` varchar(80) NOT NULL,
  `price` int(10) unsigned NOT NULL,
  `kind` varchar(12) NOT NULL DEFAULT 'service',
  `active_npc_id` bigint(20) unsigned DEFAULT NULL,
  `active_player_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `appointment_receipt` (`player_id`,`request_key`),
  UNIQUE KEY `appointment_rental` (`rental_id`),
  UNIQUE KEY `occupied_npc` (`active_npc_id`),
  UNIQUE KEY `occupied_player` (`active_player_id`),
  KEY `appointment_expiry` (`finished_at`,`expires_at`),
  KEY `npc_history` (`npc_id`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE `corporazioni` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL DEFAULT '',
  `sito` varchar(255) DEFAULT NULL,
  `statuto` mediumtext DEFAULT NULL,
  `newstatuto` mediumtext NOT NULL,
  `idmaster` int(11) NOT NULL DEFAULT 0,
  `newidmaster` int(11) NOT NULL DEFAULT 0,
  `approvato` char(1) NOT NULL DEFAULT '0',
  `city` int(11) NOT NULL DEFAULT 0,
  `budget` bigint(20) NOT NULL DEFAULT 0,
  `stipendio` char(1) NOT NULL DEFAULT '1',
  `provvigione` char(1) NOT NULL DEFAULT '0',
  `ultimostipendio` int(10) NOT NULL DEFAULT 0,
  `pvt` varchar(255) NOT NULL DEFAULT '',
  `sfondo` varchar(255) NOT NULL DEFAULT '',
  `sfondotop` varchar(255) NOT NULL DEFAULT '',
  `mappa` varchar(255) NOT NULL DEFAULT '',
  `descrizione` mediumtext NOT NULL,
  `lastpay` smallint(6) NOT NULL DEFAULT 0,
  `stendardo` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`nome`),
  KEY `id_3` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `descrizioni` (
  `chat` varchar(50) NOT NULL DEFAULT '',
  `descrizione` longtext NOT NULL,
  PRIMARY KEY (`chat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `dimore` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pg` int(11) NOT NULL DEFAULT 0,
  `city` int(11) NOT NULL DEFAULT 0,
  `indirizzo` int(11) NOT NULL DEFAULT 0,
  `numero` int(11) NOT NULL DEFAULT 0,
  `password` varchar(255) NOT NULL DEFAULT '',
  `sfondo` varchar(255) NOT NULL DEFAULT '',
  `sfondotop` varchar(255) NOT NULL DEFAULT '',
  `mappa` varchar(255) NOT NULL DEFAULT '',
  `descrizione` mediumtext NOT NULL,
  `lastpay` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `diritti` (
  `bacheca` varchar(50) DEFAULT NULL,
  `needadmin` char(1) NOT NULL DEFAULT '0',
  `needfatepower` char(1) NOT NULL DEFAULT '0',
  `needadminferiti` char(1) NOT NULL DEFAULT '0',
  `needaccademia` char(1) NOT NULL DEFAULT '0',
  `all` char(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `discussioni` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `replyid` int(10) NOT NULL DEFAULT 0,
  `data` int(10) NOT NULL DEFAULT 0,
  `oggetto` varchar(255) NOT NULL DEFAULT '',
  `messaggio` longtext NOT NULL,
  `tipo` varchar(255) DEFAULT NULL,
  `idpg` int(10) NOT NULL DEFAULT 0,
  `ontop` char(1) NOT NULL DEFAULT '0',
  `chiusa` char(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `elezioni` (
  `idvotante` int(11) NOT NULL DEFAULT 0,
  `idcandidato` int(11) NOT NULL DEFAULT 0,
  KEY `groups_voter` (`idvotante`),
  KEY `groups_candidate` (`idcandidato`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `email_dispatch_state` (
  `state_key` varchar(30) NOT NULL,
  `state_value` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`state_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `email_notification_outbox` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `recipient` int(11) NOT NULL,
  `event_key` varchar(190) NOT NULL,
  `category` varchar(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `path` varchar(1000) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `retry_at` bigint(20) NOT NULL DEFAULT 0,
  `sent_at` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recipient_event` (`recipient`,`event_key`),
  KEY `delivery` (`status`,`retry_at`),
  KEY `daily` (`recipient`,`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `equip` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `corp` int(11) NOT NULL DEFAULT 0,
  `destinatario` int(11) NOT NULL DEFAULT 0,
  `destorg` int(11) NOT NULL DEFAULT 0,
  `shortDesc` varchar(255) NOT NULL DEFAULT '',
  `desc` mediumtext NOT NULL,
  `datacreazione` int(10) DEFAULT 0,
  `datamodifica` int(10) NOT NULL DEFAULT 0,
  `immagine` varchar(255) NOT NULL DEFAULT '',
  `restore` smallint(5) unsigned NOT NULL DEFAULT 0,
  `sacro` char(1) DEFAULT '0',
  `durata` int(11) unsigned NOT NULL DEFAULT 100,
  `approvato` char(1) NOT NULL DEFAULT '0',
  `visibile` char(1) NOT NULL DEFAULT '1',
  `peso` float NOT NULL DEFAULT 0,
  `tipo` int(11) NOT NULL DEFAULT 0,
  `idcreatore` int(10) NOT NULL DEFAULT 0,
  `lavorazione` varchar(255) NOT NULL DEFAULT '',
  `taglia` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `mani` tinyint(1) NOT NULL DEFAULT 1,
  `materiaprima` varchar(255) NOT NULL DEFAULT '',
  `ferro` int(11) NOT NULL DEFAULT 0,
  `piombo` int(11) NOT NULL DEFAULT 0,
  `carbone` int(11) NOT NULL DEFAULT 0,
  `legno` int(11) NOT NULL DEFAULT 0,
  `rame` int(11) NOT NULL DEFAULT 0,
  `oro` int(11) NOT NULL DEFAULT 0,
  `argento` int(11) NOT NULL DEFAULT 0,
  `platino` int(11) NOT NULL DEFAULT 0,
  `diamante` int(11) NOT NULL DEFAULT 0,
  `zolfo` int(11) NOT NULL DEFAULT 0,
  `salnitro` int(11) NOT NULL DEFAULT 0,
  `cera` int(11) NOT NULL DEFAULT 0,
  `cotone` int(11) NOT NULL DEFAULT 0,
  `silicio` int(11) NOT NULL DEFAULT 0,
  `cuoio` int(11) NOT NULL DEFAULT 0,
  `canapa` int(11) NOT NULL DEFAULT 0,
  `seta` int(11) NOT NULL DEFAULT 0,
  `erbe` int(11) NOT NULL DEFAULT 0,
  `game_rules` text NOT NULL DEFAULT ('{}'),
  `department` varchar(100) NOT NULL DEFAULT '',
  `acqua` int(11) NOT NULL DEFAULT 0,
  `vetro` int(11) NOT NULL DEFAULT 0,
  `cereali` int(11) NOT NULL DEFAULT 0,
  `carne` int(11) NOT NULL DEFAULT 0,
  `miele` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`,`tipo`),
  KEY `Id` (`id`),
  FULLTEXT KEY `descrizione` (`desc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `equipcorp` (
  `id` bigint(11) NOT NULL AUTO_INCREMENT,
  `materia` varchar(255) NOT NULL DEFAULT '',
  `corp` int(11) NOT NULL DEFAULT 0,
  `qta` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `groups_corp_material` (`corp`,`materia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `equipment_breeding` (
  `model_id` int(11) NOT NULL,
  `available` int(11) NOT NULL DEFAULT 0,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`model_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `equipment_conditions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_kind` varchar(3) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL DEFAULT 0,
  `family` varchar(80) NOT NULL,
  `effect` text NOT NULL,
  `expires_round` int(11) NOT NULL DEFAULT 0,
  `expires_at` bigint(20) NOT NULL DEFAULT 0,
  `source_instance` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `owner_effects` (`owner_kind`,`owner_id`,`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `equipment_declarations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `round_number` int(11) NOT NULL,
  `owner_kind` varchar(3) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `instance_id` int(11) NOT NULL,
  `operation` varchar(20) NOT NULL,
  `snapshot` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `once_per_turn` (`session_id`,`round_number`,`owner_kind`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `equipment_ledger` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_key` varchar(150) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_id` int(11) NOT NULL,
  `instance_id` int(11) NOT NULL,
  `operation` varchar(30) NOT NULL,
  `session_id` int(11) NOT NULL DEFAULT 0,
  `round_number` int(11) NOT NULL DEFAULT 0,
  `before_state` text NOT NULL,
  `after_state` text NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_key` (`request_key`),
  KEY `actor_history` (`actor_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `equipment_state` (
  `instance_id` int(11) NOT NULL,
  `charges` int(11) NOT NULL,
  `cycles` int(11) NOT NULL DEFAULT 0,
  `ready_round` int(11) NOT NULL DEFAULT 0,
  `ready_at` bigint(20) NOT NULL DEFAULT 0,
  `fed_until` bigint(20) NOT NULL DEFAULT 0,
  `mode` varchar(20) NOT NULL DEFAULT '',
  `updated_at` bigint(20) NOT NULL DEFAULT 0,
  `session_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`instance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `equippg` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uid` bigint(20) unsigned NOT NULL DEFAULT 0,
  `idpg` int(10) NOT NULL DEFAULT 0,
  `slot` varchar(255) NOT NULL DEFAULT '',
  `nome` varchar(255) NOT NULL DEFAULT '',
  `consumo` int(11) NOT NULL DEFAULT 0,
  `idpadre` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `etnie` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL DEFAULT '',
  `descrizione` mediumtext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `fortune_rounds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `player_id` int(11) NOT NULL,
  `request_key` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `resource_id` bigint(20) unsigned NOT NULL,
  `resource_key` varchar(30) NOT NULL,
  `resource_name` varchar(255) NOT NULL,
  `image` varchar(255) NOT NULL,
  `multiplier` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `started_at` bigint(20) NOT NULL,
  `awarded_at` bigint(20) NOT NULL DEFAULT 0,
  `available_at` bigint(20) NOT NULL DEFAULT 0,
  `inventory_ids` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt` (`player_id`,`request_key`),
  KEY `history` (`player_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `game_operation_keys` (
  `actor` int(11) NOT NULL,
  `operation_scope` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`actor`,`operation_scope`,`request_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `game_preference_migrations` (
  `migration_key` varchar(80) NOT NULL,
  `applied_at` bigint(20) NOT NULL,
  PRIMARY KEY (`migration_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `game_welcome` (
  `id` tinyint(3) unsigned NOT NULL,
  `title` varchar(120) NOT NULL,
  `body` text NOT NULL,
  `enabled` tinyint(4) NOT NULL DEFAULT 1,
  `revision` int(10) unsigned NOT NULL DEFAULT 1,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `game_welcome_receipts` (
  `user_id` int(11) NOT NULL,
  `revision` int(10) unsigned NOT NULL,
  `read_at` bigint(20) NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `giocate` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idpg` int(11) NOT NULL DEFAULT 0,
  `data` int(10) NOT NULL DEFAULT 0,
  `giocata` longtext NOT NULL,
  `nome` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `historical_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `collection_key` varchar(80) NOT NULL,
  `source_name` varchar(190) NOT NULL,
  `source_sha256` char(64) NOT NULL,
  `source_blob` mediumblob NOT NULL,
  `title` varchar(500) NOT NULL,
  `location_label` varchar(255) NOT NULL,
  `started_at` bigint(20) DEFAULT NULL,
  `creator` varchar(255) NOT NULL,
  `header_present` tinyint(4) NOT NULL,
  `message_count` int(11) NOT NULL,
  `participants_json` mediumtext NOT NULL,
  `messages_json` mediumtext NOT NULL,
  `metadata_json` text NOT NULL,
  `imported_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `source_once` (`collection_key`,`source_name`),
  KEY `by_date` (`started_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `indirizzi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `indirizzo` varchar(255) NOT NULL DEFAULT '',
  `city` int(11) NOT NULL DEFAULT 0,
  `costo` int(11) NOT NULL DEFAULT 0,
  `disp` int(11) NOT NULL DEFAULT 0,
  `capienza` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `lavori` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat` varchar(50) NOT NULL DEFAULT '',
  `azione` varchar(255) NOT NULL DEFAULT '',
  `prob` smallint(2) NOT NULL DEFAULT 0,
  `res` bigint(20) NOT NULL DEFAULT 0,
  `frase` mediumtext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `lineage_members` (
  `player_id` int(11) NOT NULL,
  `kind` varchar(12) NOT NULL,
  `creator_id` int(11) DEFAULT NULL,
  `root_id` int(11) NOT NULL,
  `origin` varchar(20) NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'active',
  `infected_at` bigint(20) NOT NULL DEFAULT 0,
  `ready_at` bigint(20) NOT NULL DEFAULT 0,
  `transformed_at` bigint(20) NOT NULL DEFAULT 0,
  `killed_at` bigint(20) NOT NULL DEFAULT 0,
  `killer_id` int(11) NOT NULL DEFAULT 0,
  `killer_name` varchar(255) NOT NULL DEFAULT '',
  `cause_id` int(11) NOT NULL DEFAULT 0,
  `death_reason` varchar(500) NOT NULL DEFAULT '',
  PRIMARY KEY (`player_id`,`kind`),
  KEY `family` (`kind`,`root_id`),
  KEY `children` (`kind`,`creator_id`),
  KEY `pending` (`kind`,`status`,`ready_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `lineage_policy_locks` (
  `kind` varchar(12) NOT NULL,
  PRIMARY KEY (`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `logs_adminpg` (
  `data` int(10) NOT NULL DEFAULT 0,
  `esecutore` int(11) NOT NULL DEFAULT 0,
  `destinatario` int(11) NOT NULL DEFAULT 0,
  `org` int(11) NOT NULL DEFAULT 0,
  `azione` varchar(255) NOT NULL DEFAULT '',
  `qta` int(11) NOT NULL DEFAULT 0,
  `obj` int(11) NOT NULL DEFAULT 0,
  `descr` varchar(255) NOT NULL DEFAULT '',
  `chat` mediumtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `logs_deletemail` (
  `data` int(10) NOT NULL DEFAULT 0,
  `esecutore` int(11) NOT NULL DEFAULT 0,
  `destinatario` int(11) NOT NULL DEFAULT 0,
  `invio` varchar(4) NOT NULL DEFAULT '',
  `baule` varchar(4) NOT NULL DEFAULT '',
  `letto` varchar(4) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `logs_deleteuser` (
  `data` int(10) NOT NULL DEFAULT 0,
  `esecutore` int(11) NOT NULL DEFAULT 0,
  `destinatario` int(11) NOT NULL DEFAULT 0,
  `profilo` char(1) NOT NULL DEFAULT '',
  `equipaggiamento` char(1) NOT NULL DEFAULT '',
  `posta` char(1) NOT NULL DEFAULT '',
  `bacheca` char(1) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `logs_dimore` (
  `data` int(10) NOT NULL DEFAULT 0,
  `pg` int(11) NOT NULL DEFAULT 0,
  `city` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `logs_equip` (
  `data` int(10) NOT NULL DEFAULT 0,
  `esecutore` int(11) NOT NULL DEFAULT 0,
  `origine` int(11) NOT NULL DEFAULT 0,
  `destinatario` int(11) NOT NULL DEFAULT 0,
  `azione` varchar(255) NOT NULL DEFAULT '',
  `obj` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `logs_flag` (
  `data` int(10) NOT NULL DEFAULT 0,
  `esecutore` int(11) NOT NULL DEFAULT 0,
  `destinatario` int(11) NOT NULL DEFAULT 0,
  `adminorg` char(1) NOT NULL DEFAULT '0',
  `admincorp` char(1) NOT NULL DEFAULT '0',
  `fatepower` char(1) NOT NULL DEFAULT '0',
  `adminferite` char(1) NOT NULL DEFAULT '0',
  `adminmorti` char(1) NOT NULL DEFAULT '0',
  `adminstatosociale` char(1) NOT NULL DEFAULT '0',
  `smalladmin` char(1) NOT NULL DEFAULT '0',
  `bloccato` char(1) NOT NULL DEFAULT '0',
  `accademia` char(1) NOT NULL DEFAULT '0',
  `catalogo` char(1) NOT NULL DEFAULT '0',
  `ordini` char(1) NOT NULL DEFAULT '0',
  `regole` char(1) NOT NULL DEFAULT '0',
  `sindaco` char(1) NOT NULL DEFAULT '0',
  `upload` char(1) NOT NULL DEFAULT '0',
  `vacanza` char(1) NOT NULL DEFAULT '0',
  `betatester` char(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `logs_ingressi` (
  `data` int(10) NOT NULL DEFAULT 0,
  `pg` int(11) NOT NULL DEFAULT 0,
  `ip` varchar(255) NOT NULL DEFAULT '',
  `host` varchar(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `logs_meteo` (
  `data` int(10) NOT NULL DEFAULT 0,
  `azione` varchar(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `logs_profili` (
  `data` int(10) NOT NULL DEFAULT 0,
  `esecutore` int(11) NOT NULL DEFAULT 0,
  `destinatario` int(11) NOT NULL DEFAULT 0,
  `nuovonome` varchar(255) NOT NULL DEFAULT '',
  `nuovapass` varchar(255) NOT NULL DEFAULT '',
  `razza` int(11) NOT NULL DEFAULT 0,
  `sesso` varchar(10) DEFAULT NULL,
  `etnia` varchar(255) NOT NULL DEFAULT '',
  `allineamento` char(2) DEFAULT NULL,
  `infetto` char(1) NOT NULL DEFAULT '0',
  `ancestrale` char(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `logs_registrazione` (
  `data` int(10) NOT NULL DEFAULT 0,
  `nome` varchar(255) NOT NULL DEFAULT '',
  `id` int(11) NOT NULL DEFAULT 0,
  `ip` varchar(255) NOT NULL DEFAULT '',
  `host` varchar(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `logs_statistica` (
  `data` int(10) NOT NULL DEFAULT 0,
  `pg` int(11) NOT NULL DEFAULT 0,
  `ora` int(4) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `logs_visite` (
  `data` int(10) NOT NULL DEFAULT 0,
  `esecutore` int(11) NOT NULL DEFAULT 0,
  `destinatario` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `luoghi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `location` varchar(255) NOT NULL DEFAULT '',
  `nome` varchar(255) NOT NULL DEFAULT '',
  `descrizione` mediumtext NOT NULL,
  `clear` char(1) NOT NULL DEFAULT '0',
  `header_image` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `mail` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `data` int(10) NOT NULL DEFAULT 0,
  `idmittente` int(10) NOT NULL DEFAULT 0,
  `iddestinatario` int(10) NOT NULL DEFAULT 0,
  `oggetto` varchar(255) NOT NULL DEFAULT '',
  `messaggio` mediumtext NOT NULL,
  `letto` char(1) NOT NULL DEFAULT '0',
  `delmitt` char(1) NOT NULL DEFAULT '0',
  `deldest` char(1) NOT NULL DEFAULT '0',
  `baule` char(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `push_mail_recipient` (`iddestinatario`,`letto`,`deldest`,`baule`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `mappe` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_key` varchar(100) NOT NULL,
  `chat` tinyint(1) NOT NULL DEFAULT 1,
  `title` varchar(255) NOT NULL,
  `position` varchar(255) NOT NULL,
  `vampiredamage` tinyint(1) NOT NULL DEFAULT 0,
  `sfondotop` varchar(255) DEFAULT NULL,
  `sfondo` varchar(255) DEFAULT NULL,
  `mappa` varchar(255) DEFAULT NULL,
  `descrizione` text DEFAULT NULL,
  `scene_image` varchar(512) DEFAULT NULL,
  `scene_thumbnail` varchar(512) DEFAULT NULL,
  `scene_icon` varchar(32) NOT NULL DEFAULT 'compass',
  `rental_enabled` tinyint(4) NOT NULL DEFAULT 0,
  `rental_hourly_cost` int(10) unsigned NOT NULL DEFAULT 2,
  `companion_enabled` tinyint(4) NOT NULL DEFAULT 0,
  `companion_minutes` int(10) unsigned NOT NULL DEFAULT 30,
  `companion_price` int(10) unsigned NOT NULL DEFAULT 4,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_key` (`chat_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `meteo` (
  `ora` tinyint(4) NOT NULL DEFAULT 0,
  `temperatura` tinyint(4) NOT NULL DEFAULT 0,
  `tempo` int(11) NOT NULL DEFAULT 0,
  `cielo` int(11) NOT NULL DEFAULT 0,
  `vento` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `newequip` (
  `id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `corp` int(11) NOT NULL DEFAULT 0,
  `destinatario` int(11) NOT NULL DEFAULT 0,
  `destorg` int(11) NOT NULL DEFAULT 0,
  `shortDesc` varchar(255) NOT NULL DEFAULT '',
  `desc` mediumtext NOT NULL,
  `datacreazione` int(10) NOT NULL DEFAULT 0,
  `datamodifica` int(10) NOT NULL DEFAULT 0,
  `immagine` varchar(255) NOT NULL DEFAULT '',
  `restore` smallint(5) unsigned NOT NULL DEFAULT 0,
  `sacro` char(1) DEFAULT '0',
  `durata` int(11) unsigned NOT NULL DEFAULT 100,
  `approvato` char(1) NOT NULL DEFAULT '0',
  `visibile` char(1) NOT NULL DEFAULT '1',
  `peso` float NOT NULL DEFAULT 0,
  `tipo` int(11) NOT NULL DEFAULT 0,
  `idcreatore` int(10) NOT NULL DEFAULT 0,
  `lavorazione` varchar(255) NOT NULL DEFAULT '',
  `taglia` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `mani` tinyint(1) NOT NULL DEFAULT 1,
  `materiaprima` varchar(255) NOT NULL DEFAULT '',
  `ferro` int(11) NOT NULL DEFAULT 0,
  `piombo` int(11) NOT NULL DEFAULT 0,
  `carbone` int(11) NOT NULL DEFAULT 0,
  `legno` int(11) NOT NULL DEFAULT 0,
  `rame` int(11) NOT NULL DEFAULT 0,
  `oro` int(11) NOT NULL DEFAULT 0,
  `argento` int(11) NOT NULL DEFAULT 0,
  `platino` int(11) NOT NULL DEFAULT 0,
  `diamante` int(11) NOT NULL DEFAULT 0,
  `zolfo` int(11) NOT NULL DEFAULT 0,
  `salnitro` int(11) NOT NULL DEFAULT 0,
  `cera` int(11) NOT NULL DEFAULT 0,
  `cotone` int(11) NOT NULL DEFAULT 0,
  `silicio` int(11) NOT NULL DEFAULT 0,
  `cuoio` int(11) NOT NULL DEFAULT 0,
  `canapa` int(11) NOT NULL DEFAULT 0,
  `seta` int(11) NOT NULL DEFAULT 0,
  `erbe` int(11) NOT NULL DEFAULT 0,
  `game_rules` text NOT NULL DEFAULT ('{}'),
  `department` varchar(100) NOT NULL DEFAULT '',
  `acqua` int(11) NOT NULL DEFAULT 0,
  `vetro` int(11) NOT NULL DEFAULT 0,
  `cereali` int(11) NOT NULL DEFAULT 0,
  `carne` int(11) NOT NULL DEFAULT 0,
  `miele` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`,`tipo`),
  KEY `Id` (`id`),
  FULLTEXT KEY `descrizione` (`desc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `nomivietati` (
  `nome` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `notepg` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `master` int(11) NOT NULL DEFAULT 0,
  `pg` int(11) NOT NULL DEFAULT 0,
  `data` int(11) NOT NULL DEFAULT 0,
  `nota` mediumtext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `npc_deaths` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `npc_id` bigint(20) unsigned NOT NULL,
  `source_key` varchar(160) NOT NULL,
  `effect_key` varchar(120) NOT NULL DEFAULT '',
  `session_id` bigint(20) NOT NULL DEFAULT 0,
  `verdict_id` bigint(20) NOT NULL DEFAULT 0,
  `status` varchar(12) NOT NULL DEFAULT 'active',
  `killer_kind` varchar(10) NOT NULL DEFAULT 'unknown',
  `killer_id` bigint(20) NOT NULL DEFAULT 0,
  `killer_name` varchar(100) NOT NULL DEFAULT '',
  `chat_key` varchar(100) NOT NULL,
  `place_name` varchar(200) NOT NULL,
  `circumstance` text NOT NULL,
  `death_at` bigint(20) NOT NULL,
  `npc_name` varchar(100) NOT NULL,
  `portrait` varchar(512) NOT NULL,
  `story` text NOT NULL,
  `profile_snapshot` mediumtext NOT NULL,
  `created_by` bigint(20) NOT NULL DEFAULT 0,
  `created_at` bigint(20) NOT NULL,
  `reverted_at` bigint(20) NOT NULL DEFAULT 0,
  `reverted_by` bigint(20) NOT NULL DEFAULT 0,
  `revert_reason` text NOT NULL DEFAULT (''),
  `restored_hp` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `source_key` (`source_key`),
  KEY `npc_status` (`npc_id`,`status`),
  KEY `victim_registry` (`status`,`killer_kind`,`killer_id`,`death_at`),
  KEY `deaths_date` (`status`,`death_at`),
  KEY `source_effect` (`effect_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `org_ability_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `proposal_id` int(10) unsigned NOT NULL,
  `revision` int(11) NOT NULL,
  `actor_id` int(11) NOT NULL,
  `event_type` varchar(24) NOT NULL,
  `reason` text NOT NULL,
  `payload` longtext NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `proposal_history` (`proposal_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `org_ability_proposals` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `author_id` int(11) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'draft',
  `revision` int(11) NOT NULL DEFAULT 0,
  `version` int(11) NOT NULL DEFAULT 1,
  `spec` longtext NOT NULL,
  `ability_id` int(11) DEFAULT NULL,
  `decision_reason` text DEFAULT NULL,
  `reviewer_id` int(11) DEFAULT NULL,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `submitted_at` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `org_state` (`org_id`,`status`,`updated_at`),
  KEY `academy_queue` (`status`,`submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `org_ability_publications` (
  `ability_id` int(11) NOT NULL,
  `proposal_id` int(10) unsigned NOT NULL,
  `org_id` int(11) NOT NULL,
  `revision` int(11) NOT NULL,
  `spec` longtext NOT NULL,
  `approved_by` int(11) NOT NULL,
  `approved_at` bigint(20) NOT NULL,
  PRIMARY KEY (`ability_id`),
  UNIQUE KEY `proposal_id` (`proposal_id`),
  KEY `org_live` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `org_ability_receipts` (
  `actor_id` int(11) NOT NULL,
  `request_key` varchar(140) NOT NULL,
  `payload_hash` char(64) NOT NULL,
  `result` longtext NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`actor_id`,`request_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `org_ability_reviews` (
  `proposal_id` int(10) unsigned NOT NULL,
  `revision` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `opinion` varchar(16) NOT NULL,
  `reason` text NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  PRIMARY KEY (`proposal_id`,`revision`,`reviewer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `org_ability_revisions` (
  `proposal_id` int(10) unsigned NOT NULL,
  `revision` int(11) NOT NULL,
  `spec` longtext NOT NULL,
  `submitted_by` int(11) NOT NULL,
  `submitted_at` bigint(20) NOT NULL,
  PRIMARY KEY (`proposal_id`,`revision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `organizzazioni` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL DEFAULT '',
  `sito` varchar(255) DEFAULT NULL,
  `statuto` mediumtext DEFAULT NULL,
  `newstatuto` mediumtext NOT NULL DEFAULT (''),
  `idmaster` int(11) NOT NULL DEFAULT 0,
  `newidmaster` int(11) NOT NULL DEFAULT 0,
  `approvato` char(1) NOT NULL DEFAULT '0',
  `resurgo` char(1) NOT NULL DEFAULT '0',
  `cerimonia` char(1) NOT NULL DEFAULT '0',
  `ferite` char(1) NOT NULL DEFAULT '0',
  `city` varchar(255) NOT NULL DEFAULT '',
  `budget` bigint(20) NOT NULL DEFAULT 15000,
  `pvt` varchar(255) NOT NULL DEFAULT '',
  `sfondo` varchar(255) NOT NULL DEFAULT '',
  `sfondotop` varchar(255) NOT NULL DEFAULT '',
  `mappa` varchar(255) NOT NULL DEFAULT '',
  `descrizione` mediumtext NOT NULL DEFAULT (''),
  `lastpay` smallint(6) NOT NULL DEFAULT 0,
  `stendardo` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`nome`),
  KEY `id_3` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `parerisessioni` (
  `sessione` int(11) NOT NULL DEFAULT 0,
  `pg` int(11) NOT NULL DEFAULT 0,
  `parere` tinyint(4) NOT NULL DEFAULT 0,
  `motivazione` mediumtext NOT NULL,
  PRIMARY KEY (`sessione`,`pg`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `password_resets` (
  `pg` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `expires_at` bigint(20) NOT NULL,
  PRIMARY KEY (`pg`),
  UNIQUE KEY `uq_password_reset_token` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pg_nature_observations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `observer_id` bigint(20) NOT NULL,
  `subject_id` bigint(20) NOT NULL,
  `race_id` int(11) NOT NULL,
  `source_id` bigint(20) unsigned NOT NULL,
  `participation_source_id` bigint(20) unsigned NOT NULL,
  `chat_key` varchar(100) NOT NULL,
  `evidence` varchar(500) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `discovered_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt` (`observer_id`,`subject_id`,`source_id`,`race_id`),
  KEY `observer` (`observer_id`,`subject_id`,`source_id`),
  KEY `subject` (`subject_id`,`observer_id`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pg_scene_revelations` (
  `event_id` bigint(20) unsigned NOT NULL,
  `subject_id` bigint(20) NOT NULL,
  `race_id` int(11) NOT NULL,
  `evidence` varchar(500) NOT NULL,
  PRIMARY KEY (`event_id`,`subject_id`,`race_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pg_scene_witnesses` (
  `event_id` bigint(20) unsigned NOT NULL,
  `player_id` bigint(20) NOT NULL,
  `participation_source_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`event_id`,`player_id`),
  KEY `pending` (`player_id`,`participation_source_id`,`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `prestiti` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `data` int(10) NOT NULL DEFAULT 0,
  `corp` int(11) NOT NULL DEFAULT 0,
  `pgcorp` int(11) NOT NULL DEFAULT 0,
  `org` int(11) NOT NULL DEFAULT 0,
  `pgorg` int(11) NOT NULL DEFAULT 0,
  `quantita` bigint(20) NOT NULL DEFAULT 0,
  `interessi` int(11) NOT NULL DEFAULT 0,
  `stato` int(11) NOT NULL DEFAULT 0,
  `restituiti` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `prestitipg` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `data` int(10) NOT NULL DEFAULT 0,
  `org` int(11) NOT NULL DEFAULT 0,
  `pg` int(11) NOT NULL DEFAULT 0,
  `qta` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `privacy_preference_receipts` (
  `id` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `version` varchar(20) NOT NULL,
  `external_media` tinyint(4) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `expires_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_privacy_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `produzionicorporazioni` (
  `corporazione` int(11) NOT NULL DEFAULT 0,
  `produzione` int(11) NOT NULL DEFAULT 0,
  `quality` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `quest_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `recipient` int(11) NOT NULL,
  `session_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `chat_key` varchar(100) NOT NULL,
  `location_label` varchar(255) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `read_at` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quest_recipient` (`recipient`,`session_id`),
  KEY `idx_quest_unread` (`recipient`,`read_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `ramiaraldiche` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `city` int(11) NOT NULL DEFAULT 0,
  `nome` varchar(255) NOT NULL DEFAULT '',
  `livello` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `ramicorporazioni` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `corporazione` int(11) NOT NULL DEFAULT 0,
  `nome` varchar(255) NOT NULL DEFAULT '',
  `livello` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `ramiorganizzazioni` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `organizzazione` int(11) NOT NULL DEFAULT 0,
  `nome` varchar(255) NOT NULL DEFAULT '',
  `livello` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `razze` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(30) NOT NULL DEFAULT '',
  `forza` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `recuperopg` (
  `id` int(11) NOT NULL DEFAULT 0,
  `data` int(10) NOT NULL DEFAULT 0,
  `query` mediumtext NOT NULL,
  `token_hash` char(64) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `registrazioni` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pg` int(11) NOT NULL DEFAULT 0,
  `chat` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `registrazionichat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `registrazione` int(11) NOT NULL DEFAULT 0,
  `testo` mediumtext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `regole` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sezione` int(11) NOT NULL DEFAULT 0,
  `pos` int(11) NOT NULL DEFAULT 0,
  `oggetto` varchar(255) NOT NULL DEFAULT '',
  `spiegazione` longtext NOT NULL,
  `ultimamodifica` int(10) NOT NULL DEFAULT 0,
  `manual_key` varchar(100) DEFAULT NULL,
  `audience` varchar(24) NOT NULL DEFAULT 'public',
  `image` varchar(255) NOT NULL DEFAULT '',
  `summary` text DEFAULT NULL,
  `manual_source_hash` char(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `manual_key_unique` (`manual_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `richieste` (
  `idpg` bigint(20) NOT NULL DEFAULT 0,
  `organizzazione` bigint(20) NOT NULL DEFAULT 0,
  `corporazione` bigint(20) NOT NULL DEFAULT 0,
  `city` int(11) NOT NULL DEFAULT 0,
  `data` int(10) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `risondaggi` (
  `idrisposta` int(10) NOT NULL DEFAULT 0,
  `idsondaggio` int(10) NOT NULL DEFAULT 0,
  `idpg` int(10) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `rpg_abilities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ability_key` varchar(80) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(2000) NOT NULL,
  `image` varchar(512) NOT NULL DEFAULT '',
  `source` varchar(500) NOT NULL,
  `category` varchar(16) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `race_ids` text NOT NULL,
  `requirements` text NOT NULL,
  `cost` int(10) unsigned NOT NULL DEFAULT 0,
  `cooldown` int(10) unsigned NOT NULL DEFAULT 0,
  `effect` text NOT NULL,
  `enabled` tinyint(4) NOT NULL DEFAULT 1,
  `updated_at` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ability_key` (`ability_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rpg_ability_uses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_kind` varchar(3) NOT NULL,
  `owner_id` bigint(20) NOT NULL,
  `ability_id` bigint(20) unsigned NOT NULL,
  `event_key` varchar(140) NOT NULL,
  `session_id` bigint(20) NOT NULL DEFAULT 0,
  `round_number` int(11) NOT NULL DEFAULT 0,
  `snapshot` text NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `use_receipt` (`owner_kind`,`owner_id`,`event_key`),
  KEY `cooldown` (`owner_kind`,`owner_id`,`ability_id`,`session_id`,`round_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE `rpg_admin_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` bigint(20) NOT NULL,
  `action` varchar(40) NOT NULL,
  `target_id` varchar(80) NOT NULL,
  `before_state` mediumtext NOT NULL,
  `after_state` mediumtext NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rpg_characteristics` (
  `stat_key` varchar(32) NOT NULL,
  `name` varchar(80) NOT NULL,
  `description` varchar(500) NOT NULL,
  `icon` varchar(160) NOT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`stat_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rpg_chat_receipts` (
  `player_id` int(11) NOT NULL,
  `chat_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_key` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_hash` char(64) NOT NULL,
  `response_json` mediumtext NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`player_id`,`chat_key`,`request_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rpg_creation` (
  `owner_kind` varchar(3) NOT NULL,
  `owner_id` bigint(20) NOT NULL,
  `manual_points` tinyint(4) NOT NULL DEFAULT 0,
  `innates_chosen` tinyint(4) NOT NULL DEFAULT 0,
  `updated_at` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`owner_kind`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rpg_learned` (
  `owner_kind` varchar(3) NOT NULL,
  `owner_id` bigint(20) NOT NULL,
  `ability_id` bigint(20) unsigned NOT NULL,
  `cost` int(10) unsigned NOT NULL,
  `learned_at` bigint(20) NOT NULL,
  PRIMARY KEY (`owner_kind`,`owner_id`,`ability_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rpg_ledger` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_kind` varchar(3) NOT NULL,
  `owner_id` bigint(20) NOT NULL,
  `event_key` varchar(140) NOT NULL,
  `entry_type` varchar(30) NOT NULL,
  `amount` bigint(20) NOT NULL,
  `xp_total` bigint(20) unsigned NOT NULL,
  `xp_spent` bigint(20) unsigned NOT NULL,
  `reason` varchar(500) NOT NULL,
  `payload` text NOT NULL,
  `actor_id` bigint(20) NOT NULL DEFAULT 0,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt` (`owner_kind`,`owner_id`,`event_key`),
  KEY `history` (`owner_kind`,`owner_id`,`id`),
  KEY `awards` (`owner_kind`,`owner_id`,`entry_type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE `rpg_malus_state` (
  `owner_kind` varchar(3) NOT NULL,
  `owner_id` bigint(20) NOT NULL,
  `conditions` text NOT NULL,
  `reason` varchar(500) NOT NULL DEFAULT '',
  `actor_id` bigint(20) NOT NULL DEFAULT 0,
  `updated_at` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`owner_kind`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rpg_progress` (
  `owner_kind` varchar(3) NOT NULL,
  `owner_id` bigint(20) NOT NULL,
  `xp_total` bigint(20) unsigned NOT NULL DEFAULT 0,
  `xp_spent` bigint(20) unsigned NOT NULL DEFAULT 0,
  `xp_stats` bigint(20) unsigned NOT NULL DEFAULT 0,
  `xp_abilities` bigint(20) unsigned NOT NULL DEFAULT 0,
  `initial_claimed` tinyint(4) NOT NULL DEFAULT 0,
  `initial_stats` text NOT NULL,
  `trained_stats` text NOT NULL,
  `version` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`owner_kind`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rpg_races` (
  `race_id` int(10) unsigned NOT NULL,
  `base_stats` text NOT NULL,
  `description` varchar(1500) NOT NULL,
  `source` varchar(500) NOT NULL,
  `special` tinyint(4) NOT NULL DEFAULT 0,
  `enabled` tinyint(4) NOT NULL DEFAULT 1,
  `updated_at` bigint(20) NOT NULL DEFAULT 0,
  `human_default` tinyint(4) NOT NULL DEFAULT 0,
  `transformation_enabled` tinyint(4) NOT NULL DEFAULT 0,
  `transformation_human_text` text NOT NULL DEFAULT (''),
  `transformation_special_text` text NOT NULL DEFAULT (''),
  `loses_human_language` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`race_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rpg_rules_backup` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(40) NOT NULL,
  `table_name` varchar(40) NOT NULL,
  `row_data` mediumtext NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rpg_settings` (
  `setting_key` varchar(60) NOT NULL,
  `value` int(11) NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `session_archive_deletions` (
  `session_id` bigint(20) unsigned NOT NULL,
  `deleted_by` bigint(20) NOT NULL,
  `admin_name` varchar(255) NOT NULL,
  `session_title` varchar(255) NOT NULL,
  `chat_key` varchar(255) NOT NULL,
  `deleted_at` bigint(20) NOT NULL,
  `snapshot` mediumtext NOT NULL,
  PRIMARY KEY (`session_id`),
  KEY `deletion_history` (`deleted_at`,`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `session_handoffs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `actor_id` bigint(20) NOT NULL,
  `from_master` bigint(20) NOT NULL,
  `to_master` bigint(20) NOT NULL DEFAULT 0,
  `mode` varchar(12) NOT NULL,
  `source_boundary` bigint(20) NOT NULL DEFAULT 0,
  `message_count` int(11) NOT NULL DEFAULT 0,
  `transcript_hash` char(64) NOT NULL,
  `context` mediumtext NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `session_history` (`session_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `session_public_context` (
  `session_id` bigint(20) unsigned NOT NULL,
  `source_id` bigint(20) unsigned NOT NULL,
  `actor_kind` varchar(8) NOT NULL,
  `actor_id` bigint(20) NOT NULL DEFAULT 0,
  `speaker` varchar(100) NOT NULL,
  `content` text NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`session_id`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `sessioni` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat` varchar(255) NOT NULL DEFAULT '',
  `nomechat` varchar(255) NOT NULL DEFAULT '',
  `nome` varchar(255) NOT NULL DEFAULT '',
  `lock` char(1) NOT NULL DEFAULT '0',
  `run` char(1) NOT NULL DEFAULT '0',
  `dadi` char(1) NOT NULL DEFAULT '0',
  `datain` int(10) NOT NULL DEFAULT 0,
  `masterin` int(11) NOT NULL DEFAULT 0,
  `dataout` int(10) NOT NULL DEFAULT 0,
  `masterout` int(11) NOT NULL DEFAULT 0,
  `partecipanti` mediumtext NOT NULL,
  `conductor_id` bigint(20) NOT NULL DEFAULT 0,
  `control_version` int(11) NOT NULL DEFAULT 1,
  `start_source_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `sezioniregole` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pos` int(11) NOT NULL DEFAULT 0,
  `nome` varchar(255) NOT NULL DEFAULT '',
  `manual_key` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `manual_key_unique` (`manual_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `sondaggi` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `attivo` char(1) NOT NULL DEFAULT '0',
  `tema` mediumtext NOT NULL,
  `risposte` mediumtext NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `storicobacheche` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `oldid` int(11) NOT NULL DEFAULT 0,
  `data` int(10) NOT NULL DEFAULT 0,
  `oggetto` varchar(255) NOT NULL DEFAULT '',
  `messaggio` mediumtext NOT NULL,
  `idpg` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `storicoelezioni` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `city_id` int(11) NOT NULL,
  `tipologia` varchar(24) NOT NULL,
  `start_candidature` datetime NOT NULL,
  `start_elezioni` datetime NOT NULL,
  `end_elezioni` datetime NOT NULL,
  `winner_id` int(11) DEFAULT NULL,
  `consultazioni` longtext DEFAULT NULL,
  `voting_notified` tinyint(4) NOT NULL DEFAULT 0,
  `civic_state` varchar(20) DEFAULT NULL,
  `civic_ballot` int(11) NOT NULL DEFAULT 1,
  `civic_closed_at` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `city_round` (`city_id`,`winner_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `subtipi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `support_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `support_id` bigint(20) unsigned NOT NULL,
  `actor` int(11) NOT NULL,
  `target` int(11) NOT NULL,
  `action` varchar(30) NOT NULL,
  `method` varchar(10) NOT NULL,
  `path` varchar(500) NOT NULL,
  `fields_json` text NOT NULL,
  `http_status` int(11) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `session_time` (`support_id`,`id`),
  KEY `actor_time` (`actor`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `support_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor` int(11) NOT NULL,
  `target` int(11) NOT NULL,
  `reason` varchar(1000) NOT NULL,
  `started_at` bigint(20) NOT NULL,
  `expires_at` bigint(20) NOT NULL,
  `ended_at` bigint(20) NOT NULL DEFAULT 0,
  `end_reason` varchar(40) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `actor_time` (`actor`,`started_at`),
  KEY `target_time` (`target`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `tipo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL DEFAULT '',
  `tipoG` varchar(255) NOT NULL DEFAULT '',
  `subtipo` int(255) NOT NULL DEFAULT 0,
  `colore` varchar(7) NOT NULL DEFAULT '',
  `immagine` varchar(255) NOT NULL DEFAULT '',
  `padre` int(11) NOT NULL DEFAULT 0,
  `equipaggiabile` char(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `trasferimenti` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `data` int(10) NOT NULL DEFAULT 0,
  `corp` int(11) NOT NULL DEFAULT 0,
  `pgcorp` int(11) NOT NULL DEFAULT 0,
  `pg` int(11) NOT NULL DEFAULT 0,
  `obj` int(11) NOT NULL DEFAULT 0,
  `nomeobj` varchar(255) NOT NULL DEFAULT '',
  `qta` int(11) NOT NULL DEFAULT 1,
  `prezzo` bigint(20) NOT NULL DEFAULT 0,
  `stato` int(11) NOT NULL DEFAULT 0,
  `verso` char(1) NOT NULL DEFAULT '',
  `nascostopg` char(1) NOT NULL DEFAULT '0',
  `nascostocorp` char(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `groups_corp_state` (`corp`,`verso`,`stato`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `treasury_donations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `scope` varchar(20) NOT NULL,
  `scope_id` int(11) NOT NULL,
  `amount` int(11) NOT NULL,
  `interval_days` int(11) NOT NULL,
  `next_run` bigint(20) NOT NULL,
  `retry_after` bigint(20) NOT NULL DEFAULT 0,
  `enabled` tinyint(4) NOT NULL DEFAULT 1,
  `revision` int(11) NOT NULL DEFAULT 1,
  `updated_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mandate` (`user_id`,`scope`,`scope_id`),
  KEY `due` (`enabled`,`next_run`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `treasury_policies` (
  `scope` varchar(20) NOT NULL,
  `scope_id` int(11) NOT NULL,
  `enabled` tinyint(4) NOT NULL DEFAULT 0,
  `interval_days` int(11) NOT NULL DEFAULT 7,
  `next_run` bigint(20) NOT NULL DEFAULT 0,
  `retry_after` bigint(20) NOT NULL DEFAULT 0,
  `revision` int(11) NOT NULL DEFAULT 1,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` bigint(20) NOT NULL,
  PRIMARY KEY (`scope`,`scope_id`),
  KEY `due` (`enabled`,`retry_after`,`next_run`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `treasury_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `scope` varchar(20) NOT NULL,
  `scope_id` int(11) NOT NULL,
  `request_key` varchar(100) NOT NULL,
  `payload_hash` char(64) NOT NULL,
  `kind` varchar(20) NOT NULL,
  `actor_id` int(11) NOT NULL,
  `amount` bigint(20) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL,
  `detail` text NOT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt` (`scope`,`scope_id`,`request_key`),
  KEY `history` (`scope`,`scope_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `umori` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `user_experience_settings` (
  `user_id` int(11) NOT NULL,
  `map_effects` tinyint(4) NOT NULL DEFAULT 1,
  `map_lighting` tinyint(4) NOT NULL DEFAULT 1,
  `ambient_sound` tinyint(4) NOT NULL DEFAULT 0,
  `notification_sound` tinyint(4) NOT NULL DEFAULT 1,
  `ambient_volume` smallint(6) NOT NULL DEFAULT 80,
  `notification_volume` smallint(6) NOT NULL DEFAULT 80,
  `sound_theme` varchar(20) NOT NULL DEFAULT 'castle',
  `updated_at` bigint(20) NOT NULL,
  `confirmed_at` bigint(20) NOT NULL DEFAULT 0,
  `relationship_effects` varchar(10) NOT NULL DEFAULT 'private',
  `chess_skin` varchar(20) NOT NULL DEFAULT 'classic',
  `email_preferences` longtext DEFAULT NULL,
  `email_enabled_at` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `user_policy_acceptances` (
  `user_id` int(11) NOT NULL,
  `policy_version` varchar(40) NOT NULL,
  `accepted_at` bigint(20) NOT NULL,
  `adult_confirmed` tinyint(4) NOT NULL,
  `terms_accepted` tinyint(4) NOT NULL,
  `ai_policy_accepted` tinyint(4) NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `utenti` (
  `id` int(100) NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) NOT NULL DEFAULT '',
  `password` varchar(255) NOT NULL DEFAULT '',
  `email` varchar(100) NOT NULL DEFAULT '',
  `visite` bigint(20) unsigned NOT NULL DEFAULT 0,
  `tdcolor` varchar(255) NOT NULL DEFAULT '',
  `css1` varchar(255) NOT NULL DEFAULT '',
  `css2` varchar(255) NOT NULL DEFAULT '',
  `css3` varchar(255) NOT NULL DEFAULT '#000000',
  `sameloc` varchar(255) NOT NULL DEFAULT '',
  `sfondochat` varchar(255) NOT NULL DEFAULT '',
  `araldica` int(10) NOT NULL DEFAULT 1,
  `allineamento` char(2) NOT NULL DEFAULT 'NN',
  `avatar` varchar(255) NOT NULL DEFAULT '',
  `avatar2` varchar(255) NOT NULL DEFAULT '',
  `musica` varchar(255) NOT NULL DEFAULT '',
  `musica2` varchar(255) NOT NULL DEFAULT '',
  `contatti` varchar(255) DEFAULT NULL,
  `razza` int(10) NOT NULL DEFAULT 2,
  `etnia` int(11) NOT NULL DEFAULT 0,
  `infetto` char(1) NOT NULL DEFAULT '0',
  `senziente` char(1) NOT NULL DEFAULT '0',
  `azionilican` int(11) NOT NULL DEFAULT 0,
  `ultimatrasformazione` int(10) NOT NULL DEFAULT 0,
  `ancestrale` char(1) NOT NULL DEFAULT '0',
  `sesso` varchar(20) NOT NULL DEFAULT '',
  `corporazione` int(10) DEFAULT NULL,
  `ramocorporazione` int(11) NOT NULL DEFAULT 0,
  `caricacorporazione` int(10) DEFAULT NULL,
  `organizzazione` int(10) DEFAULT NULL,
  `ramoorganizzazione` int(11) NOT NULL DEFAULT 0,
  `caricaorganizzazione` int(10) DEFAULT NULL,
  `note` mediumtext DEFAULT NULL,
  `firma` mediumtext DEFAULT NULL,
  `psicologia` mediumtext DEFAULT NULL,
  `amicizie` mediumtext DEFAULT NULL,
  `affetti` mediumtext DEFAULT NULL,
  `animali` mediumtext DEFAULT NULL,
  `storiapg` longtext DEFAULT NULL,
  `motto` mediumtext DEFAULT NULL,
  `motto2` mediumtext DEFAULT NULL,
  `dataregistrazione` int(10) NOT NULL DEFAULT 0,
  `fatepower` char(1) NOT NULL DEFAULT '0',
  `ferite` varchar(255) DEFAULT NULL,
  `denari` bigint(20) unsigned DEFAULT 0,
  `lavoro` int(10) NOT NULL DEFAULT 0,
  `puntif` int(11) DEFAULT 1000,
  `admin` char(1) DEFAULT '0',
  `sindaco` char(1) NOT NULL DEFAULT '0',
  `adminmorti` char(1) DEFAULT '0',
  `admincorporazione` char(1) DEFAULT '0',
  `adminorganizzazione` char(1) DEFAULT '0',
  `admintrasfcorp` char(1) NOT NULL DEFAULT '0',
  `admincatalogo` char(1) DEFAULT '0',
  `adminferite` char(1) DEFAULT '0',
  `adminregole` char(1) NOT NULL DEFAULT '0',
  `bloccato` char(1) DEFAULT '0',
  `datasblocco` int(10) NOT NULL DEFAULT 0,
  `motivo` text NOT NULL,
  `lockby` int(11) NOT NULL DEFAULT 0,
  `posizione` varchar(255) DEFAULT 'off',
  `datamorte` int(10) NOT NULL DEFAULT 0,
  `ultimoingresso` int(10) NOT NULL DEFAULT 0,
  `ultimauscita` int(10) NOT NULL DEFAULT 0,
  `lastmove` int(10) NOT NULL DEFAULT 0,
  `provincia` varchar(50) DEFAULT NULL,
  `chat` varchar(255) NOT NULL DEFAULT '',
  `adminaccademia` char(1) NOT NULL DEFAULT '0',
  `statosociale` varchar(60) DEFAULT NULL,
  `adminstatosociale` char(1) NOT NULL DEFAULT '0',
  `imprigionato` int(11) NOT NULL DEFAULT 0,
  `upload` char(1) NOT NULL DEFAULT '0',
  `vacanza` char(1) NOT NULL DEFAULT '0',
  `banner` varchar(255) NOT NULL DEFAULT '',
  `banner2` varchar(255) NOT NULL DEFAULT '',
  `vampire_human_form` tinyint(4) NOT NULL DEFAULT 0,
  `telegram` varchar(65) NOT NULL DEFAULT '',
  `discord` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`,`nome`),
  UNIQUE KEY `uq_utenti_id` (`id`),
  UNIQUE KEY `uq_utenti_nome` (`nome`),
  KEY `nome` (`nome`),
  FULLTEXT KEY `password` (`password`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `weather_assets` (
  `asset_key` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `label` varchar(120) NOT NULL,
  `description` varchar(500) NOT NULL,
  `image_path` varchar(512) NOT NULL,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`asset_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `web_push_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `endpoint_hash` char(64) NOT NULL,
  `endpoint` varchar(2048) NOT NULL,
  `p256dh` varchar(128) NOT NULL,
  `auth` varchar(64) NOT NULL,
  `vapid_hash` char(64) NOT NULL,
  `enabled` tinyint(4) NOT NULL DEFAULT 1,
  `last_mail_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `failures` smallint(5) unsigned NOT NULL DEFAULT 0,
  `available_at` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` int(10) unsigned NOT NULL,
  `updated_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `push_endpoint` (`endpoint_hash`),
  KEY `push_user` (`user_id`),
  KEY `push_due` (`enabled`,`available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE world_areas (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 city_id INT NOT NULL DEFAULT 0, slug VARCHAR(100) NOT NULL UNIQUE,
 title VARCHAR(120) NOT NULL, description TEXT NOT NULL,
 image VARCHAR(512) NOT NULL DEFAULT '/assets/placeholder.svg',
 enabled TINYINT NOT NULL DEFAULT 1, position INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE mappe ADD area_id INT UNSIGNED NULL, ADD public_enabled TINYINT NOT NULL DEFAULT 1, ADD sort_order INT NOT NULL DEFAULT 0, ADD KEY area_index (area_id,public_enabled);
CREATE TABLE engine_admin_audit (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, actor INT NOT NULL,
 action VARCHAR(80) NOT NULL, entity_id INT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE `engine_setup_steps` (
 `step_key` varchar(32) NOT NULL,
 `status` varchar(16) NOT NULL DEFAULT 'pending',
 `updated_by` int NOT NULL DEFAULT 0,
 `updated_at` bigint NOT NULL DEFAULT 0,
 PRIMARY KEY (`step_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE `rpg_races` ADD `transformation_stats` text NOT NULL DEFAULT ('{}');
ALTER TABLE `utenti` ADD `alternate_form` tinyint NOT NULL DEFAULT 0;
ALTER TABLE `city` ADD `elections_enabled` tinyint NOT NULL DEFAULT 0;
ALTER TABLE `razze` AUTO_INCREMENT=100;
