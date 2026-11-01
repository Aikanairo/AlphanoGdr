<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/libs/ai-worker.inc.php';
if(!alphano_config('ai_enabled')){echo "Supporto IA disabilitato.\n";exit;}
// Bounded worker for cron or an external supervisor. Never installs a model.
$deadline=microtime(true)+45;while(microtime(true)<$deadline&&ai_work_once()){}
