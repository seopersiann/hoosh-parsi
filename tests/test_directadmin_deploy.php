<?php
declare(strict_types=1);
require dirname(__DIR__).'/deploy/directadmin/deploy.php';
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function fails(callable $fn,string $message):void{try{$fn();}catch(Throwable $e){return;}throw new RuntimeException($message);}
$base=sys_get_temp_dir().'/hoosh-test-'.bin2hex(random_bytes(5));mkdir($base,0700);mkdir($base.'/public_html');mkdir($base.'/private');
$config=require dirname(__DIR__).'/deploy/directadmin/config.example.php';$config['public_root']=$base.'/public_html';$config['token_file']=$base.'/private/no-token.txt';
file_put_contents($base.'/private/config.php','<?php return '.var_export($config,true).';');$d=new HooshDeploy($base.'/private/config.php');
foreach(['../index.html','/index.html','.env','assets/../../secret.txt','assets/a.php','uploads/photo.jpg','assets/uploads/a.webp','deliverables/file.html','deploy/index.html','assets/a\\b.svg'] as $p)check(!$d->allowed($p),'Unsafe path allowed: '.$p);
foreach(['new-page/index.html','assets/new/picture.webp','sitemap_index.xml','robots.txt'] as $p)check($d->allowed($p),'Expected path rejected.');
function fixture(string $base,string $version,array $extra=[]):array{
 $content=['index.html'=>'<html lang="fa"><body>هوشمند پارسی '.$version.'</body></html>','styles.css'=>'body{color:'.$version.'}','robots.txt'=>'User-agent: *'];$content+=$extra;
 $entries=[];$zip=new ZipArchive();$path=$base.'/'.$version.'.zip';$zip->open($path,ZipArchive::CREATE);
 foreach($content as $p=>$value){$zip->addFromString('repository-root/'.$p,$value);$entries[]=['path'=>$p,'sha'=>sha1('blob '.strlen($value)."\0".$value),'type'=>'blob','mode'=>'100644','size'=>strlen($value)];}
 $zip->addFromString('repository-root/deploy/secret.php','not public');$zip->close();return [$path,$entries];
}
[$zip,$tree]=fixture($base,'one',['old-page/index.html'=>'old page']);$files=$d->stageArchive($zip,$tree,$base.'/private/stage-one');
check(!file_exists($base.'/private/stage-one/deploy/secret.php'),'Private source leaked.');
file_put_contents($base.'/public_html/index.html','original site');file_put_contents($base.'/public_html/.htaccess','host settings');mkdir($base.'/public_html/uploads');file_put_contents($base.'/public_html/uploads/photo.jpg','user upload');file_put_contents($base.'/public_html/unowned.html','manual page');
$one=['commit'=>str_repeat('a',40),'stage'=>$base.'/private/stage-one','files'=>$files];$d->publish($one,[]);
check(file_get_contents($base.'/public_html/.htaccess')==='host settings','Host settings changed.');check(file_get_contents($base.'/public_html/uploads/photo.jpg')==='user upload','User upload changed.');check(file_get_contents($base.'/public_html/unowned.html')==='manual page','Unowned file changed.');
$state=json_decode(file_get_contents($base.'/private/runtime/state.json'),true);
[$zip2,$tree2]=fixture($base,'two',['new-page/index.html'=>'new page']);$files2=$d->stageArchive($zip2,$tree2,$base.'/private/stage-two');$two=['commit'=>str_repeat('b',40),'stage'=>$base.'/private/stage-two','files'=>$files2];
file_put_contents($base.'/public_html/styles.css','manual hosting edit');fails(fn()=>$d->publish($two,$state),'Manual edit was overwritten.');check(file_get_contents($base.'/public_html/styles.css')==='manual hosting edit','Drift not preserved.');copy($base.'/private/stage-one/styles.css',$base.'/public_html/styles.css');
$d->publish($two,$state);check(!file_exists($base.'/public_html/old-page/index.html'),'Obsolete managed page not removed.');check(file_exists($base.'/public_html/new-page/index.html'),'New page not installed.');
$state2=json_decode(file_get_contents($base.'/private/runtime/state.json'),true);$journal=json_decode(file_get_contents($base.'/private/backups/'.$state2['backup'].'/manifest.json'),true);
// Simulate a process killed during a partial release, before state/journal finalization.
file_put_contents($base.'/private/runtime/journal.json',json_encode($journal));file_put_contents($base.'/public_html/index.html','partially published');$d->run('--status');
check(file_get_contents($base.'/public_html/index.html')===file_get_contents($base.'/private/stage-one/index.html'),'Crash recovery did not restore homepage.');check(file_exists($base.'/public_html/old-page/index.html'),'Crash recovery did not restore removed page.');check(!file_exists($base.'/public_html/new-page/index.html'),'Crash recovery did not remove new page.');check(file_exists($base.'/private/runtime/paused.json'),'Recovery must pause auto publication.');
file_put_contents($base.'/broken.zip','partial zip bytes');fails(fn()=>$d->stageArchive($base.'/broken.zip',$tree,$base.'/private/broken'),'Corrupt ZIP accepted.');
$bad=$tree;$bad[0]['sha']=str_repeat('0',40);fails(fn()=>$d->stageArchive($zip,$bad,$base.'/private/hash-mismatch'),'Bad Git hash accepted.');
$z=new ZipArchive();$z->open($base.'/traversal.zip',ZipArchive::CREATE);$z->addFromString('root/../escape','bad');$z->close();fails(fn()=>$d->stageArchive($base.'/traversal.zip',$tree,$base.'/private/traversal'),'ZIP traversal accepted.');
rmdir($base.'/public_html/new-page');symlink($base.'/private',$base.'/public_html/new-page');fails(fn()=>$d->publish($two,$state),'Destination symlink accepted.');unlink($base.'/public_html/new-page');
$lock=fopen($base.'/private/runtime/deploy.lock','c');flock($lock,LOCK_EX);ob_start();$d->run('--status');$output=ob_get_clean();flock($lock,LOCK_UN);fclose($lock);check(str_contains($output,'Another deployment'),'Concurrent operation not blocked.');
// Manual rollback returns to pre-install files and pauses (no repeated cron rollback).
$d->run('--resume');$d->run('--rollback');check(file_get_contents($base.'/public_html/index.html')==='original site','Manual rollback did not restore initial version.');$d->run('--rollback');check(file_get_contents($base.'/public_html/index.html')==='original site','Repeated rollback changed restored files.');
echo "PASS: path restrictions, integrity, partial ZIP, drift protection, managed deletion, backups, crash recovery, locking, rollback and upload preservation.\n";
