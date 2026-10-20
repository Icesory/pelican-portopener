<?php
namespace PortOpener\Services;
use Illuminate\Validation\ValidationException;
/** Pure resolver: input must come from trusted panel models, never client requests. */
class AllocationResolver {
    public function resolve(array $server, array $allocations, array $variables, array $rules, array $policy): array {
        $fail = fn (string $message) => throw ValidationException::withMessages(['port_opener'=>$message]);
        if (!($policy['enabled'] ?? false)) $fail('Port exposure is disabled for this node.');
        if (!filter_var($policy['internal_address'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $fail('Node requires a valid IPv4 destination.');
        if (($server['status'] ?? null) !== null) $fail('Server must be installed, available and not suspended.');
        if ((int)$server['node_id'] !== (int)$policy['node_id']) $fail('Server node does not match policy.');
        $rules = array_filter($rules, fn ($r) => $r['public'] ?? false);
        if (!$rules) $fail('No approved public egg port definitions exist.');
        $rows = [];
        foreach ($rules as $rule) {
            $protocols = $rule['protocols'] ?? [];
            if (!$protocols || array_diff($protocols, ['TCP','UDP'])) $fail('Every public rule requires TCP or UDP.');
            if ($rule['source'] === 'primary_allocation') {
                $matches = array_filter($allocations, fn ($a) => (int)$a['id'] === (int)$server['allocation_id']);
            } elseif ($rule['source'] === 'variable') {
                $value = $variables[$rule['variable_name'] ?? ''] ?? null;
                if (!is_scalar($value) || !preg_match('/^[0-9]{1,5}$/D', (string)$value)) $fail('Port variable is missing or invalid.');
                $matches = array_filter($allocations, fn ($a) => (int)$a['port'] === (int)$value);
            } elseif ($rule['source'] === 'allocation_alias') {
                $alias=$rule['allocation_alias'] ?? null;
                if (!is_string($alias) || $alias==='') $fail('Allocation alias rule requires a nonempty alias.');
                $matches=array_filter($allocations,fn($a)=>($a['ip_alias'] ?? null)===$alias);
                if (!$matches) $fail('Allocation alias matches no assigned allocation.');
            } else $fail('Unsupported port rule source.');
            if ($rule['source']!=='allocation_alias' && count($matches) !== 1) $fail('Port rule must resolve to exactly one assigned allocation.');
            foreach ($matches as $a) {
            if ((int)$a['server_id'] !== (int)$server['id'] || (int)$a['node_id'] !== (int)$server['node_id']) $fail('Allocation does not belong to this server and node.');
            $port = (int)$a['port'];
            if ($port < max(1, (int)$policy['min_port']) || $port > min(65535, (int)$policy['max_port'])) $fail('Port is outside the approved node range.');
            foreach (array_unique($protocols) as $protocol) {
                $id = $server['uuid'].':'.$a['id'].':'.strtolower($protocol);
                $rows[$id] = ['mapping_id'=>$id,'server_uuid'=>$server['uuid'],'allocation_id'=>(int)$a['id'],'internal_address'=>$policy['internal_address'],'port'=>$port,'protocol'=>$protocol];
            }
            }
        }
        ksort($rows); return array_values($rows);
    }
}
