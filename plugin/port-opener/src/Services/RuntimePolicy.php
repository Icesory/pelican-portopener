<?php
namespace PortOpener\Services;
use Carbon\CarbonInterface;
class RuntimePolicy {
    public function evaluate(string $state,?string $previous,?CarbonInterface $offlineSince,CarbonInterface $now):array {
        if($state==='unknown')return ['include'=>null,'offline_since'=>$offlineSince,'policy_state'=>'unknown'];
        if($state==='running')return ['include'=>true,'offline_since'=>null,'policy_state'=>'active'];
        // Transitions preserve an existing known-running window; never open a cold start.
        if(in_array($state,['starting','stopping'],true)) {
            if($previous!=='running' && !$offlineSince)return ['include'=>false,'offline_since'=>null,'policy_state'=>'waiting'];
            $since=$offlineSince ?? $now;
            return ['include'=>$since->gt($now->copy()->subSeconds(120)),'offline_since'=>$since,'policy_state'=>$since->gt($now->copy()->subSeconds(120)) ? 'grace':'waiting'];
        }
        $since=$offlineSince ?? $now;
        $wasActive=$previous==='running' || $offlineSince!==null;
        $include=$wasActive && $since->gt($now->copy()->subSeconds(120));
        // An initially offline server never receives grace on its next offline poll.
        if(!$wasActive)$since=null;
        return ['include'=>$include,'offline_since'=>$since,'policy_state'=>$include ? 'grace':'waiting'];
    }
}
