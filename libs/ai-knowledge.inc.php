<?php
declare(strict_types=1);
function ai_knowledge_validate(array $in):array{
 $v=[];foreach(['title'=>160,'topic'=>80,'origin'=>20,'source'=>512,'summary'=>6000] as $k=>$max)$v[$k]=ai_field($in,$k,$max,in_array($k,['title','topic','origin','summary'],true));
 if(!in_array($v['origin'],['official','general'],true))throw new DomainException('Origine non valida.',422);
 if($v['source']!==''&&!preg_match('~^(https://|manuale:)[^\s<>]+$~D',$v['source']))throw new DomainException('Usa un collegamento HTTPS o manuale:ID.',422);
 $v['active']=ai_integer($in,'active',0,1);$v['mechanics']=$in['mechanics']??[];
 if(!is_array($v['mechanics'])||($v['mechanics']!==[]&&array_is_list($v['mechanics'])))throw new DomainException('Criteri tecnici non validi.',422);
 if($v['origin']==='general'&&$v['mechanics'])throw new DomainException('Le conoscenze generali non autorizzano meccaniche.',422);
 if(array_diff(array_keys($v['mechanics']),['initiative','damage','resolution','max_range','reload_rounds','charge_space','armor_absorption']))throw new DomainException('Criterio non riconosciuto.',422);
 foreach(['damage'=>maxpuntif-1,'max_range'=>1000,'reload_rounds'=>20,'charge_space'=>1000,'armor_absorption'=>1000] as $k=>$max)if(isset($v['mechanics'][$k]))$v['mechanics'][$k]=ai_integer($v['mechanics'],$k,0,$max);
 if(isset($v['mechanics']['initiative'])&&!in_array($v['mechanics']['initiative'],['entry','force'],true))throw new DomainException('Ordine non valido.',422);
 if(isset($v['mechanics']['resolution'])&&!in_array($v['mechanics']['resolution'],['review','opposed_force_d20'],true))throw new DomainException('Risoluzione non valida.',422);
 return $v;
}
/** General lore is quoted as data only and never becomes a rule by retrieval. */
function ai_knowledge_retrieve(string $question,int $limit=3):array{
 $terms=array_unique(preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($question),-1,PREG_SPLIT_NO_EMPTY));$out=[];
 foreach(ai_db()->query('SELECT * FROM ai_master_knowledge WHERE active=1 ORDER BY origin,id') as $r){$score=0;$text=mb_strtolower($r['topic'].' '.$r['title'].' '.$r['summary']);foreach($terms as $t)if(mb_strlen($t)>3&&mb_strpos($text,$t)!==false)$score++;if($score){$r['score']=$score+($r['origin']==='official'?100:0);$out[]=$r;}}
 usort($out,static fn($a,$b)=>$b['score']<=>$a['score']);return array_slice($out,0,$limit);
}
function ai_knowledge_policy(int $id):array{
 if(!$id)return ['resolution'=>'review','initiative'=>'entry'];
 $r=ai_db()->query("SELECT * FROM ai_master_knowledge WHERE id=? AND origin='official' AND active=1",[$id])[0]??null;
 if(!$r)throw new DomainException('La regola tecnica selezionata non è ufficiale o attiva.',422);
 $m=json_decode($r['mechanics'],true)?:[];return ['knowledge_id'=>$id,'knowledge_version'=>(int)$r['version'],'source'=>$r['source'],'title'=>$r['title'],...$m];
}
