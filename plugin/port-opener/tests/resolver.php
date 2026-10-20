<?php
// Dependency-free resolver tests: php tests/resolver.php
namespace Illuminate\Validation {
    class ValidationException extends \RuntimeException {
        public static function withMessages(array $messages): self { return new self(implode(' ', $messages)); }
    }
}
namespace {
    require __DIR__.'/../src/Services/AllocationResolver.php';
    $r = new \PortOpener\Services\AllocationResolver;
    $s = ['id'=>1,'uuid'=>'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa','node_id'=>1,'allocation_id'=>10,'status'=>null];
    $a = [['id'=>10,'port'=>54321,'server_id'=>1,'node_id'=>1],['id'=>11,'port'=>54322,'server_id'=>1,'node_id'=>1]];
    $p = ['enabled'=>true,'node_id'=>1,'internal_address'=>'192.168.178.50','min_port'=>54000,'max_port'=>54999];
    $rules = [['source'=>'primary_allocation','protocols'=>['TCP','UDP'],'public'=>true]];
    $checks = 0;
    $check = function ($condition, $name) use (&$checks) { if (!$condition) throw new \RuntimeException('FAIL: '.$name); $checks++; };
    $reject = function ($server,$allocations,$variables,$definitions,$policy,$name) use ($r,$check) {
        try { $r->resolve($server,$allocations,$variables,$definitions,$policy); $check(false,$name); }
        catch (\Illuminate\Validation\ValidationException $e) { $check(true,$name); }
    };
    $rows = $r->resolve($s,$a,[],$rules,$p);
    $check(count($rows)===2 && $rows[0]['port']===54321,'TCP+UDP separate mappings');
    $variable = [['source'=>'variable','variable_name'=>'QUERY_PORT','protocols'=>['UDP'],'public'=>true]];
    $check($r->resolve($s,$a,['QUERY_PORT'=>'54322'],$variable,$p)[0]['allocation_id']===11,'variable assigned allocation');
    $reject($s,$a,[],[],$p,'missing metadata');
    $reject($s,$a,[],[['source'=>'primary_allocation','protocols'=>[],'public'=>true]],$p,'missing protocol');
    $reject($s,$a,[],[['source'=>'primary_allocation','protocols'=>['SCTP'],'public'=>true]],$p,'unsupported protocol');
    $reject($s,$a,['QUERY_PORT'=>'54323'],$variable,$p,'unassigned variable port');
    $reject($s,$a,['QUERY_PORT'=>'54322;rm'],$variable,$p,'invalid variable');
    $reject($s,array_replace($a,[0=>array_replace($a[0],['server_id'=>2])]),[],$rules,$p,'cross-server allocation');
    $reject(array_replace($s,['status'=>'suspended']),$a,[],$rules,$p,'suspended excluded');
    $reject(array_replace($s,['node_id'=>2]),$a,[],$rules,$p,'node mismatch');
    $reject($s,$a,[],$rules,array_replace($p,['enabled'=>false]),'disabled node');
    $reject($s,$a,[],$rules,array_replace($p,['max_port'=>54320]),'excluded port');
    $reject($s,$a,[],$rules,array_replace($p,['internal_address'=>'::1']),'IPv6 excluded');
    $check(count($r->resolve($s,$a,[],array_merge($rules,$rules),$p))===2,'duplicate rule idempotent');
    $valheim=[['id'=>10,'port'=>2456,'server_id'=>1,'node_id'=>1,'ip_alias'=>'Valheim'],['id'=>11,'port'=>2457,'server_id'=>1,'node_id'=>1,'ip_alias'=>'Valheim'],['id'=>12,'port'=>2458,'server_id'=>1,'node_id'=>1,'ip_alias'=>'Other']];
    $vp=array_replace($p,['min_port'=>2000,'max_port'=>3000]);
    $alias=[['source'=>'allocation_alias','allocation_alias'=>'Valheim','protocols'=>['UDP'],'public'=>true]];
    $vr=$r->resolve($s,$valheim,[],$alias,$vp);
    $check(array_column($vr,'port')===[2456,2457],'alias exposes both Valheim UDP allocations only');
    $check(array_column($vr,'protocol')===['UDP','UDP'],'alias protocol remains explicit');
    $check(count($r->resolve($s,$valheim,[],array_merge($alias,[['source'=>'primary_allocation','protocols'=>['UDP'],'public'=>true]]),$vp))===2,'overlapping alias primary deduplicates');
    $reject($s,$valheim,[],[['source'=>'allocation_alias','allocation_alias'=>'valheim','protocols'=>['UDP'],'public'=>true]],$vp,'alias is case sensitive');
    $reject($s,$valheim,[],[['source'=>'allocation_alias','allocation_alias'=>' Valheim ','protocols'=>['UDP'],'public'=>true]],$vp,'runtime alias is not trimmed');
    $reject($s,$valheim,[],[['source'=>'allocation_alias','allocation_alias'=>'','protocols'=>['UDP'],'public'=>true]],$vp,'empty alias rejected');
    $reject($s,array_replace($valheim,[1=>array_replace($valheim[1],['server_id'=>2])]),[],$alias,$vp,'alias cannot cross server ownership');
    $reject($s,$valheim,[],$alias,array_replace($vp,['max_port'=>2456]),'every alias matched port obeys range');
    echo "$checks resolver checks passed\n";
}
