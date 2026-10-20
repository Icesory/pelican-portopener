<?php
namespace PortOpener\Services;
use Illuminate\Support\Facades\Validator;
class Ipv6Report {
    public static function sanitize(array $report, $allowed, array $approved): array {
        $data=Validator::make($report,[
            'enabled'=>'required|boolean','state'=>'required|in:disabled,available,error,simulation',
            'address'=>'nullable|ipv6','interface'=>'nullable|string|max:32', 'error_code'=>'nullable|string|max:64',
            'mappings'=>'present|array|max:512','mappings.*.mapping_id'=>'required|string|max:128',
            'mappings.*.port'=>'required|integer|between:1,65535','mappings.*.protocol'=>'required|in:TCP,UDP',
            'mappings.*.address'=>'required|ipv6','mappings.*.state'=>'required|in:pending,mapped,blocked,error,removing',
            'mappings.*.error_code'=>'nullable|string|max:64','mappings.*.expires_at'=>'nullable|numeric',
        ])->validate();
        $code=fn($value)=>is_string($value)&&preg_match('/^[a-z0-9_]{1,64}$/D',$value)?$value:null;
        $rows=[];
        foreach($data['mappings'] as $row){
            $expected=$allowed->get($row['mapping_id']);
            abort_unless($expected && (int)$expected['port']===(int)$row['port'] && $expected['protocol']===$row['protocol'],422,'IPv6 mapping is outside this node snapshot.');
            abort_unless($row['state']==='removing' || in_array($row['mapping_id'],$approved,true),422,'IPv6 mapping is not approved.');
            $rows[]=['mapping_id'=>$row['mapping_id'],'port'=>(int)$row['port'],'protocol'=>$row['protocol'],'address'=>$row['address'],'state'=>$row['state'],'error_code'=>$code($row['error_code'] ?? null)];
        }
        return ['enabled'=>(bool)$data['enabled'],'state'=>$data['state'],'address'=>$data['address'] ?? null,'interface'=>$data['interface'] ?? null,'error_code'=>$code($data['error_code'] ?? null),'mappings'=>$rows];
    }
}
