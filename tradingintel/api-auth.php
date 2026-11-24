<?php
declare(strict_types=1);
// Header-only credentials. Never accept tokens from URLs or cookies.
function ti_api_token(array $server, array $headers=[]): ?string {
    $authorization=[];
    foreach (['HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (isset($server[$key]) && trim((string)$server[$key])!=='') $authorization[]=(string)$server[$key];
    }
    $fallback=[];
    if (isset($server['HTTP_X_API_TOKEN']) && trim((string)$server['HTTP_X_API_TOKEN'])!=='') $fallback[]=(string)$server['HTTP_X_API_TOKEN'];
    foreach ($headers as $name=>$value) {
        if (strcasecmp((string)$name,'Authorization')===0 && trim((string)$value)!=='') $authorization[]=(string)$value;
        if (strcasecmp((string)$name,'X-Api-Token')===0 && trim((string)$value)!=='') $fallback[]=(string)$value;
    }
    $tokens=[];
    foreach ($authorization as $value) {
        if (!preg_match('/^\s*Bearer\s+([a-f0-9]{64})\s*$/iD',$value,$match)) return null;
        $tokens[]=$match[1];
    }
    foreach ($fallback as $value) {
        $value=trim($value);
        if (!preg_match('/^[a-f0-9]{64}$/D',$value)) return null;
        $tokens[]=$value;
    }
    if (!$tokens || count(array_unique($tokens))!==1) return null;
    return $tokens[0];
}
function ti_api_authorized(string $file, array $server, array $headers=[]): bool {
    $token=ti_api_token($server,$headers);
    $saved=@file_get_contents($file);
    return $token!==null && is_string($saved) &&
        preg_match('/^[a-f0-9]{64}$/D',trim($saved))===1 &&
        hash_equals(trim($saved),hash('sha256',$token));
}
