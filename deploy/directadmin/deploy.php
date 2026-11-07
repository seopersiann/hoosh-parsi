<?php
declare(strict_types=1);
/* CLI only. The installer, configuration and all runtime data must remain outside every web root. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);

final class HooshDeploy
{
    private array $c;
    private string $base;
    private string $public;
    private string $token = '';
    private $lock;
    public function __construct(string $config)
    {
        if (!is_file($config) || is_link($config)) throw new RuntimeException('Missing private config.php.');
        $this->c = require $config;
        $this->base = realpath(dirname($config)) ?: '';
        $this->public = realpath($this->c['public_root']) ?: '';
        if (!$this->base || !$this->public || is_link($this->c['public_root']) || $this->base === $this->public || str_starts_with($this->base, $this->public.'/')) throw new RuntimeException('Invalid private/public paths.');
        if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $this->c['repository']) || $this->c['branch'] !== 'main') throw new RuntimeException('Invalid repository/branch.');
        if (!in_array($this->c['mode'], ['dry-run', 'live'], true)) throw new RuntimeException('Invalid mode.');
        foreach (['curl', 'zip', 'json'] as $ext) if (!extension_loaded($ext)) throw new RuntimeException('Missing PHP extension: '.$ext);
        foreach (['runtime', 'backups'] as $dir) $this->mkdir($this->base.'/'.$dir);
        chmod($this->base,0700); chmod($config,0600);
        $tokenPath=$this->c['token_file'];
        if (is_file($tokenPath)) {
            $real=realpath($tokenPath);
            if (is_link($tokenPath) || !str_starts_with($real, $this->base.'/')) throw new RuntimeException('Token must be a private file in this directory.');
            chmod($tokenPath,0600);$this->token=trim((string)file_get_contents($tokenPath));
            if (preg_match('/[\r\n]/',$this->token)) throw new RuntimeException('Invalid token file.');
        }
    }
    private function mkdir(string $path): void { if (!is_dir($path) && !mkdir($path,0700,true)) throw new RuntimeException('Cannot create private directory.'); }
    private function json(string $path, array $data): void {
        $tmp=$path.'.tmp';$raw=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
        if (file_put_contents($tmp,$raw,LOCK_EX)!==strlen($raw) || !rename($tmp,$path)) throw new RuntimeException('Cannot save private state.');
        chmod($path,0600);
    }
    private function read(string $path): array {
        if (!is_file($path)) return [];
        return json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    }
    private function log(string $message): void {
        $path=$this->base.'/runtime/deploy.log';
        if (is_file($path) && filesize($path)>5242880) rename($path,$path.'.1');
        $line=gmdate('c').' '.$message.PHP_EOL;
        file_put_contents($path,$line,FILE_APPEND|LOCK_EX);echo $line;
    }
    public function allowed(string $path): bool {
        if ($path==='' || str_contains($path,'\\') || str_contains($path,':') || preg_match('/[\x00-\x1f\x7f]/',$path)) return false;
        $parts=explode('/',$path);
        foreach ($parts as $p) if ($p==='' || $p==='.' || $p==='..' || $p[0]==='.') return false;
        foreach (array_merge(['deploy','scripts','tests','deliverables','node_modules','vendor','_hosting_release','_hosting_backup'], $this->c['protected_paths']) as $deny) {
            if ($path===$deny || str_starts_with(strtolower($path),strtolower($deny).'/')) return false;
        }
        $extension=strtolower(pathinfo($path,PATHINFO_EXTENSION));
        if (!in_array($extension,$this->c['allowed_extensions'],true)) return false;
        if (count($parts)===1) return in_array($extension,['html','css','js','xml'],true) || $path==='robots.txt';
        if (in_array($parts[0],$this->c['allowed_roots'],true)) return true;
        return $extension==='html'; // New content routes are allowed without listing page names.
    }
    private function target(string $path): string {
        if (!$this->allowed($path)) throw new RuntimeException('Path is not managed by this project.');
        $target=$this->public; foreach (explode('/',$path) as $part) {
            $target.='/'.$part;
            if (is_link($target)) throw new RuntimeException('Symlink in managed destination.');
        }
        if (file_exists($target) && !is_file($target)) throw new RuntimeException('Managed destination is not a file.');
        return $target;
    }
    private function request(string $url, ?string $destination=null): string {
        // Redirects are followed manually: Authorization never goes to codeload or any other host.
        for ($redirect=0;$redirect<4;$redirect++) {
            $host=parse_url($url,PHP_URL_HOST);
            if (parse_url($url,PHP_URL_SCHEME)!=='https' || !in_array($host,['api.github.com','codeload.github.com'],true)) throw new RuntimeException('Untrusted download host.');
            $ch=curl_init($url);$headers=['Accept: application/vnd.github+json'];
            if ($host==='api.github.com' && $this->token!=='') $headers[]='Authorization: Bearer '.$this->token;
            $location='';$length=0;$body='';$file=$destination!==null?fopen($destination,'wb'):null;
            if ($destination!==null && !$file) throw new RuntimeException('Cannot open download destination.');
            $limit=$destination!==null?$this->c['max_archive_bytes']:8388608;
            curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>20,CURLOPT_TIMEOUT=>180,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'HooshParsi-Private-Deploy/1.0',CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$location){if(stripos($line,'Location:')===0)$location=trim(substr($line,9));return strlen($line);},
                CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$length,&$body,$file,$limit){$length+=strlen($chunk);if($length>$limit)return 0;if($file)return fwrite($file,$chunk);$body.=$chunk;return strlen($chunk);}]);
            $ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_errno($ch);curl_close($ch);if($file)fclose($file);
            if ($ok===false || $error) throw new RuntimeException('GitHub network/download failure, cURL code '.$error.'.');
            if (in_array($status,[301,302,303,307,308],true) && $location!=='') {$url=$location;continue;}
            if ($status!==200) throw new RuntimeException('GitHub HTTP '.$status.'; check repository, token, quota and network.');
            return $body;
        }
        throw new RuntimeException('Too many download redirects.');
    }
    private function api(string $suffix): array {return json_decode($this->request('https://api.github.com/repos/'.$this->c['repository'].'/'.$suffix),true,512,JSON_THROW_ON_ERROR);}
    private function blobHash(string $path): string {
        $h=hash_init('sha1');hash_update($h,'blob '.filesize($path)."\0");$f=fopen($path,'rb');hash_update_stream($h,$f);fclose($f);return hash_final($h);
    }
    public function stageArchive(string $archive,array $entries,string $stage): array {
        $files=[];$total=0;
        foreach ($entries as $e) {
            if (!$this->allowed($e['path'])) continue;
            if (($e['type']??'')!=='blob' || ($e['mode']??'')!=='100644') throw new RuntimeException('Only regular public files are allowed.');
            if (!preg_match('/^[a-f0-9]{40}$/',$e['sha']) || !isset($e['size']) || $e['size']<0) throw new RuntimeException('Invalid Git tree file.');
            $files[$e['path']]=['git_sha'=>$e['sha'],'size'=>$e['size']];$total+=$e['size'];
        }
        if (!isset($files['index.html'],$files['styles.css'],$files['robots.txt']) || count($files)>$this->c['max_files'] || $total>$this->c['max_release_bytes']) throw new RuntimeException('Incomplete or oversized release tree.');
        if (disk_free_space($this->base)<3*$total+52428800) throw new RuntimeException('Insufficient private staging/backup disk space.');
        $this->mkdir($stage);$zip=new ZipArchive();
        if ($zip->open($archive,ZipArchive::CHECKCONS)!==true) throw new RuntimeException('Incomplete/corrupt ZIP.');
        $seen=[];$root=null;
        try {
            for($i=0;$i<$zip->numFiles;$i++) {
                $stat=$zip->statIndex($i);$name=$stat['name'];
                if (str_contains($name,'\\') || preg_match('~(^/|(^|/)\.\.?(/|$)|[\x00-\x1f])~',$name)) throw new RuntimeException('Unsafe archive path.');
                $parts=explode('/',$name,2);if($root===null)$root=$parts[0];if($root!==$parts[0])throw new RuntimeException('Archive contains multiple roots.');
                $path=$parts[1]??'';if(!isset($files[$path]))continue;
                if(isset($seen[$path]))throw new RuntimeException('Duplicate archive entry.');$seen[$path]=true;
                $opsys=0;$attr=0;$zip->getExternalAttributesIndex($i,$opsys,$attr);
                if ((($attr>>16)&0170000)===0120000 || $stat['size']!==$files[$path]['size']) throw new RuntimeException('Symlink or invalid archive size.');
                $dest=$stage.'/'.$path;$this->mkdir(dirname($dest));$in=$zip->getStream($name);$out=fopen($dest,'xb');
                if (!$in || !$out) throw new RuntimeException('Cannot stage archive file.');
                $copied=stream_copy_to_stream($in,$out,$files[$path]['size']+1);fclose($in);fclose($out);
                if ($copied!==$files[$path]['size'] || $this->blobHash($dest)!==$files[$path]['git_sha']) throw new RuntimeException('Git blob integrity mismatch.');
                $files[$path]['sha256']=hash_file('sha256',$dest);
            }
        } finally {$zip->close();}
        if (count($seen)!==count($files)) throw new RuntimeException('Archive is missing required project files.');
        $html=(string)file_get_contents($stage.'/index.html');
        if (!str_contains($html,'<html') || !str_contains($html,'</html>') || !str_contains($html,'هوشمند پارسی')) throw new RuntimeException('Homepage validation failed.');
        return $files;
    }
    private function verifyStage(array $candidate): void {
        foreach ($candidate['files'] as $path=>$meta) {
            $f=$candidate['stage'].'/'.$path;
            if (!$this->allowed($path) || is_link($f) || !is_file($f) || hash_file('sha256',$f)!==$meta['sha256']) throw new RuntimeException('Staging changed; run dry-run again.');
            $this->target($path);
        }
    }
    private function checkDrift(array $state): void {
        foreach ($state['files']??[] as $path=>$meta) {
            $f=$this->target($path);
            if (!is_file($f) || hash_file('sha256',$f)!==$meta['sha256']) throw new RuntimeException('Managed files were edited on hosting; publication stopped to preserve changes.');
        }
    }
    private function installFile(string $source,string $path,int $mode=0644): void {
        $target=$this->target($path);$parent=dirname($target);
        if (!is_dir($parent)) {
            if (!mkdir($parent,0755,true) && !is_dir($parent)) throw new RuntimeException('Cannot create public directory.');
        }
        $tmp=$parent.'/.hoosh-deploy-'.bin2hex(random_bytes(8));
        if (!copy($source,$tmp)) throw new RuntimeException('Cannot stage destination file.');
        chmod($tmp,$mode);
        if (hash_file('sha256',$tmp)!==hash_file('sha256',$source) || !rename($tmp,$target)) { @unlink($tmp);throw new RuntimeException('Atomic destination replacement failed.'); }
    }
    public function publish(array $candidate,array $state): void {
        $this->verifyStage($candidate);$this->checkDrift($state);
        $paths=array_unique(array_merge(array_keys($candidate['files']),array_keys($state['files']??[])));
        $id=gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));$backup=$this->base.'/backups/'.$id;$this->mkdir($backup.'/files');$snapshot=[];
        foreach ($paths as $path) {
            $f=$this->target($path);$snapshot[$path]=['existed'=>is_file($f)];
            if (is_file($f)) {
                $copy=$backup.'/files/'.$path;$this->mkdir(dirname($copy));
                if (!copy($f,$copy) || hash_file('sha256',$f)!==hash_file('sha256',$copy)) throw new RuntimeException('Backup failed; public files not changed.');
                $snapshot[$path]+=['sha256'=>hash_file('sha256',$copy),'mode'=>fileperms($f)&0777];
            }
        }
        $journal=['backup'=>$id,'snapshot'=>$snapshot,'previous_state'=>$state,'new_files'=>$candidate['files']];
        $this->json($backup.'/manifest.json',$journal);
        $this->json($this->base.'/runtime/journal.json',$journal); // Durable recovery record before the first mutation.
        try {
            $ordered=array_keys($candidate['files']);
            usort($ordered,fn($a,$b)=>(($a==='index.html'?2:(str_ends_with($a,'.html')?1:0))<=>($b==='index.html'?2:(str_ends_with($b,'.html')?1:0)))?:strcmp($a,$b));
            foreach ($ordered as $path) $this->installFile($candidate['stage'].'/'.$path,$path);
            foreach ($state['files']??[] as $path=>$meta) if (!isset($candidate['files'][$path])) {
                $f=$this->target($path);if(is_file($f) && !unlink($f)) throw new RuntimeException('Cannot remove obsolete managed file.');
            }
            foreach($candidate['files'] as $path=>$meta) if(hash_file('sha256',$this->target($path))!==$meta['sha256'])throw new RuntimeException('Post-publication verification failed.');
            $this->json($this->base.'/runtime/state.json',['commit'=>$candidate['commit'],'files'=>$candidate['files'],'backup'=>$id,'published_at'=>gmdate('c')]);
            if(!unlink($this->base.'/runtime/journal.json'))throw new RuntimeException('Cannot finalize journal.');
            $this->log('PUBLISHED commit='.$candidate['commit'].' files='.count($candidate['files']).' backup='.$id);
        } catch (Throwable $e) { $this->restore($journal);throw $e; }
    }
    private function restore(array $journal): void {
        $id=$journal['backup'];if(!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/',$id))throw new RuntimeException('Invalid backup ID.');
        $backup=$this->base.'/backups/'.$id;
        // Verify every backup first. Keep the journal on failure so cron cannot advance.
        foreach($journal['snapshot'] as $path=>$meta) if($meta['existed']) {
            $f=$backup.'/files/'.$path;
            if (!is_file($f) || is_link($f) || hash_file('sha256',$f)!==$meta['sha256']) throw new RuntimeException('Backup damaged; manual recovery required.');
        }
        foreach($journal['snapshot'] as $path=>$meta) {
            if($meta['existed']) $this->installFile($backup.'/files/'.$path,$path,$meta['mode']);
            else {
                $f=$this->target($path);
                if(is_file($f)) {
                    if(hash_file('sha256',$f)!==($journal['new_files'][$path]['sha256']??''))throw new RuntimeException('New file changed externally; rollback stopped to preserve it.');
                    if(!unlink($f))throw new RuntimeException('Cannot remove new project file during rollback.');
                }
            }
        }
        $this->json($this->base.'/runtime/state.json',$journal['previous_state']);
        $this->json($this->base.'/runtime/paused.json',['reason'=>'rollback','time'=>gmdate('c')]);
        if(is_file($this->base.'/runtime/journal.json') && !unlink($this->base.'/runtime/journal.json'))throw new RuntimeException('Cannot finalize recovery.');
        $this->log('ROLLED_BACK backup='.$id.'; automatic publishing is PAUSED.');
    }
    private function policyHash(): string {return hash('sha256',json_encode([$this->c['repository'],$this->c['branch'],$this->public,$this->c['allowed_roots'],$this->c['allowed_extensions'],$this->c['protected_paths']]));}
    public function run(string $operation=''): void {
        $this->lock=fopen($this->base.'/runtime/deploy.lock','c');
        if(!$this->lock || !flock($this->lock,LOCK_EX|LOCK_NB)) {echo "Another deployment is running; skipped.\n";return;}
        try {
            $journal=$this->read($this->base.'/runtime/journal.json');
            if($journal){$this->restore($journal);return;}
            $state=$this->read($this->base.'/runtime/state.json');
            if($operation==='--status'){$this->log('STATUS commit='.($state['commit']??'none').' mode='.$this->c['mode'].' paused='.(is_file($this->base.'/runtime/paused.json')?'yes':'no'));return;}
            if($operation==='--rollback') {
                if(is_file($this->base.'/runtime/paused.json')){$this->log('PAUSED; rollback not repeated.');return;}
                if(empty($state['backup']))throw new RuntimeException('No previous version backup exists.');
                $this->checkDrift($state);$this->restore($this->read($this->base.'/backups/'.$state['backup'].'/manifest.json'));return;
            }
            if($operation==='--resume'){if(is_file($this->base.'/runtime/paused.json'))unlink($this->base.'/runtime/paused.json');$this->log('RESUMED; next scheduled check will run.');return;}
            if(!in_array($operation,['','--dry-run'],true))throw new RuntimeException('Unknown operation.');
            if(is_file($this->base.'/runtime/paused.json')){$this->log('PAUSED; use --resume after inspecting logs.');return;}
            $head=$this->api('commits/'.rawurlencode($this->c['branch']));$sha=$head['sha']??'';
            if(!preg_match('/^[a-f0-9]{40}$/',$sha))throw new RuntimeException('Invalid GitHub commit response.');
            if(($state['commit']??'')===$sha){$this->log('UNCHANGED commit='.$sha);return;}
            $candidate=$this->read($this->base.'/runtime/candidate.json');
            if(($candidate['commit']??'')!==$sha || ($candidate['policy']??'')!==$this->policyHash()) {
                $tree=$this->api('git/trees/'.$sha.'?recursive=1');
                if(!isset($tree['tree']) || !empty($tree['truncated']))throw new RuntimeException('Incomplete GitHub tree.');
                $stage=$this->base.'/runtime/stage-'.$sha.'-'.bin2hex(random_bytes(3));$archive=$this->base.'/runtime/archive.zip';
                $this->request('https://api.github.com/repos/'.$this->c['repository'].'/zipball/'.$sha,$archive);
                $files=$this->stageArchive($archive,$tree['tree'],$stage);unlink($archive);
                $candidate=['commit'=>$sha,'stage'=>$stage,'files'=>$files,'policy'=>$this->policyHash(),'validated_at'=>gmdate('c')];
                $this->json($this->base.'/runtime/candidate.json',$candidate);
            }
            $this->verifyStage($candidate);$this->checkDrift($state);
            if($this->c['mode']==='dry-run' || $operation==='--dry-run') {
                $this->json($this->base.'/runtime/dry-run-ok.json',['commit'=>$sha,'policy'=>$this->policyHash(),'time'=>gmdate('c')]);
                $this->log('DRY_RUN_OK commit='.$sha.' files='.count($candidate['files']).'; public files untouched.');return;
            }
            // A successful dry run on this host and policy is required before the first live deployment.
            $receipt=$this->read($this->base.'/runtime/dry-run-ok.json');
            if(!$state && (($receipt['commit']??'')!==$sha || ($receipt['policy']??'')!==$this->policyHash()))throw new RuntimeException('First deployment requires a successful dry-run for this commit.');
            $this->publish($candidate,$state);
        } catch(Throwable $e) {$this->log('ERROR '.$e->getMessage());throw $e;}
        finally {flock($this->lock,LOCK_UN);fclose($this->lock);}
    }
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try {
        $updater=new HooshDeploy(__DIR__.'/config.php');$updater->run($argv[1]??'');
    } catch(Throwable $e) {fwrite(STDERR,'Deployment stopped: '.$e->getMessage().PHP_EOL);exit(1);}
}
