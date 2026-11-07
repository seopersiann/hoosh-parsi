<?php
// Private CLI-only diagnostics. Never place this directory inside public_html.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
$result = array('time' => gmdate('c'), 'php' => PHP_VERSION, 'binary' => PHP_BINARY, 'sapi' => PHP_SAPI,
 'extensions' => array('curl'=>extension_loaded('curl'),'zip'=>class_exists('ZipArchive'),'json'=>extension_loaded('json')),
 'functions' => array('flock'=>function_exists('flock'),'rename'=>function_exists('rename'),'hash_file'=>function_exists('hash_file')),
 'private_path'=>__DIR__, 'public_path'=>dirname(__DIR__).'/public_html',
 'private_writable'=>is_writable(__DIR__), 'public_writable'=>is_writable(dirname(__DIR__).'/public_html'),
 'open_basedir'=>ini_get('open_basedir'), 'memory_limit'=>ini_get('memory_limit'));
if (extension_loaded('curl')) {
 $ch=curl_init('https://api.github.com/repos/seopersiann/hoosh-parsi/commits/main');
 curl_setopt_array($ch,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'HooshParsi-Deploy-Doctor',CURLOPT_HTTPHEADER=>array('Accept: application/vnd.github+json')));
 $body=curl_exec($ch); $result['github_http']=curl_getinfo($ch,CURLINFO_HTTP_CODE);$result['github_network_error']=curl_errno($ch);curl_close($ch);
 $data=json_decode((string)$body,true);$result['github_commit']=isset($data['sha'])?$data['sha']:null;
}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
