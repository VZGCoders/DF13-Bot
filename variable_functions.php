<?php

/*
 * This file is a part of the DF13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */ 

use \DF13\DF13;
use \Discord\Builders\MessageBuilder;
use \Discord\Parts\Embed\Embed;
use \Discord\Parts\User\Activity;
use \Discord\Parts\Interactions\Command\Command;
use \Discord\Parts\Permissions\RolePermission;
use \React\EventLoop\Timer\Timer;
use \React\Promise\ExtendedPromiseInterface;

$set_ips = function (DF13 $DF13): void
{ //on ready
    $vzg_ip = gethostbyname('www.valzargaming.com');
    $external_ip = file_get_contents('http://ipecho.net/plain');
    $DF13->ips = [
        'df13' => $external_ip,
        'tdm' => $external_ip,
        'vzg' => $vzg_ip,
    ];
    $DF13->ports = [
        'df13' => '7778',
        'tdm' => '7778',
        'persistence' => '7777',
        'bc' => '1717', 
        'kepler' => '1718',
    ];
};

$status_changer = function ($discord, $activity, $state = 'online'): void
{
    $discord->updatePresence($activity, false, $state);
};
$status_changer_random = function (DF13 $DF13) use ($status_changer): bool
{ //on ready
    if (! $DF13->files['status_path']) {
        unset($DF13->timers['status_changer_timer']);
        $DF13->logger->warning('status_path is not defined');
        return false;
    }
    if (! $status_array = file($DF13->files['status_path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) {
        unset($DF13->timers['status_changer_timer']);
        $DF13->logger->warning("unable to open file `{$DF13->files['status_path']}`");
        return false;
    }
    
    list($status, $type, $state) = explode('; ', $status_array[array_rand($status_array)]);
    if ($status) {
        $activity = new Activity($DF13->discord, [ //Discord status            
            'name' => $status,
            'type' => (int) $type, //0, 1, 2, 3, 4 | Game/Playing, Streaming, Listening, Watching, Custom Status
        ]);
        $status_changer($DF13->discord, $activity, $state);
    }
    return true;
};
$status_changer_timer = function (DF13 $DF13) use ($status_changer_random): void
{ //on ready
    $DF13->timers['status_changer_timer'] = $DF13->discord->getLoop()->addPeriodicTimer(120, function() use ($DF13, $status_changer_random) { $status_changer_random($DF13); });
};

$ban = function (DF13 $DF13, $array, $message = null) use ($ban, $ban_tdm): string
{
    $admin = ($message ? $message->author->displayname : $DF13->discord->user->username);
    $txt = "$admin:::{$array[0]}:::{$array[1]}:::{$array[2]}" . PHP_EOL;
    $result = '';
    if ($file = fopen($DF13->files['discord2ban'], 'a')) {
        fwrite($file, $txt);
        fclose($file);
    } else {
        $DF13->logger->warning("unable to open {$DF13->files['discord2ban']}");
        $result .= "unable to open {$DF13->files['discord2ban']}" . PHP_EOL;
    }
    $result .= "**$admin** banned **{$array[0]}** from **DF13** for **{$array[1]}** with the reason **{$array[2]}**" . PHP_EOL;
    return $result;
};

$unban = function (DF13 $DF13, string $ckey, ?string $admin = null): void
{
    if (! $admin) $admin = $DF13->discord->user->displayname;
    if ($file = fopen($DF13->files['discord2unban'], 'a')) {
        fwrite($file, "$admin:::$ckey");
        fclose($file);
    }
};

$browser_call = function (DF13 $DF13, string $url, string $method = 'GET', array $headers = [], array|string $data = [], $curl = true): false|string|ExtendedPromiseInterface
{
    if (! is_string($data)) $data = http_build_query($data);
    if ( ! $curl && $browser = $DF13->browser) return $browser->{$method}($url, $headers, $data);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //return the transfer as a string
    switch ($method) {
        case 'GET':
            break;
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            break;
        default:
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    $result = curl_exec($ch);
    return $result;
};

$host = function (DF13 $DF13): void
{
    \execInBackground("python3 {$DF13->files['updateserverabspaths']}");
    \execInBackground("rm -f {$DF13->files['serverdata']}");
    \execInBackground("python3 {$DF13->files['killsudos']}");
    $DF13->discord->getLoop()->addTimer(30, function() use ($DF13) {
        \execInBackground("DreamDaemon {$DF13->files['dmb']} {$DF13->ports['df13']} -trusted -webclient -logself &");
    });
};
$kill = function (DF13 $DF13): void
{
    \execInBackground("python3 {$DF13->files['killDF13']}");
};
$restart = function (DF13 $DF13) use ($kill, $host): void
{
    $kill($DF13);
    $host($DF13);
};
$host_tdm = function (DF13 $DF13): void
{
    \execInBackground("python3 {$DF13->files['tdm_updateserverabspaths']}");
    \execInBackground("rm -f {$DF13->files['tdm_serverdata']}");
    \execInBackground("python3 {$DF13->files['tdm_killsudos']}");
    $DF13->discord->getLoop()->addTimer(30, function() use ($DF13) {
        \execInBackground("DreamDaemon {$DF13->files['tdm_dmb']} {$DF13->ports['tdm']} -trusted -webclient -logself &");
    });
};
$kill_tdm = function (DF13 $DF13): void
{
    \execInBackground("python3 {$DF13->files['tdm_killDF13']}");
};
$restart_tdm = function (DF13 $DF13) use ($kill_tdm, $host_tdm): void
{
    $kill_tdm($DF13);
    $host_tdm($DF13);
};
$mapswap = function (DF13 $DF13, string $mapto): bool
{
    if (! $file = fopen($DF13->files['map_defines_path'], 'r')) return false;
    
    $maps = array();
    while (($fp = fgets($file, 4096)) !== false) {
        $linesplit = explode(' ', trim(str_replace('"', '', $fp)));
        if (isset($linesplit[2]) && $map = trim($linesplit[2])) $maps[] = $map;
    }
    fclose($file);
    if (! in_array($mapto, $maps)) return false;
    
    \execInBackground("python3 {$DF13->files['mapswap_df13']} $mapto");
    return true;
};

$filenav = function (DF13 $DF13, string $basedir, array $subdirs) use (&$filenav): array
{
    $scandir = scandir($basedir);
    unset($scandir[1], $scandir[0]);
    if (! $subdir = trim(array_shift($subdirs))) return [false, $scandir];
    if (! in_array($subdir, $scandir)) return [false, $scandir, $subdir];
    if (is_file("$basedir/$subdir")) return [true, "$basedir/$subdir"];
    return $filenav($DF13, "$basedir/$subdir", $subdirs);
};
$log_handler = function (DF13 $DF13, $message, string $message_content) use ($filenav)
{
    $tokens = explode(';', $message_content);
    if (!in_array(trim($tokens[0]), ['df13', 'tdm'])) return $message->reply('Please use the format `logs df13;folder;file` or `logs tdm;folder;file`');
    if (trim($tokens[0]) == 'df13') {
        unset($tokens[0]);
        $results = $filenav($DF13, $DF13->files['log_basedir'], $tokens);
    } else {
        unset($tokens[0]);
        $results = $filenav($DF13, $DF13->files['tdm_log_basedir'], $tokens);
    }
    if ($results[0]) return $message->reply(MessageBuilder::new()->addFile($results[1], 'log.txt'));
    if (count($results[1]) > 7) $results[1] = [array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1])];
    if (! isset($results[2]) || ! $results[2]) return $message->reply('Available options: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
    return $message->reply("{$results[2]} is not an available option! Available options: " . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
};
$banlog_handler = function (DF13 $DF13, $message, string $message_content_lower)
{
    if (!in_array($message_content_lower, ['df13', 'tdm'])) return $message->reply('Please use the format `bans df13` or `bans tdm');
    if ($message_content_lower == 'df13') return $message->reply(MessageBuilder::new()->addFile($DF13->files['bans'], 'bans.txt'));
    return $message->reply(MessageBuilder::new()->addFile($DF13->files['tdm_bans'], 'bans.txt'));
};

$tests = function (DF13 $DF13, $message, string $message_content)
{
    $tokens = explode(' ', $message_content);
    if (! $tokens[0]) {
        if (empty($DF13->tests)) return $message->reply("No tests have been created yet! Try creating one with `tests test_key add {Your Test's Question}`");
        return $message->reply('Available tests: `' . implode('`, `', array_keys($DF13->tests)) . '`');
    }
    if (! isset($tokens[1]) || (! array_key_exists($test_key = $tokens[0], $DF13->tests) && $tokens[1] != 'add')) return $message->reply("Test `$test_key` hasn't been created yet! Please add a question first.");
    if ($tokens[1] == 'list') return $message->reply(MessageBuilder::new()->addFileFromContent("$test_key.txt", var_export($DF13->tests[$test_key], true)));
    if ($tokens[1] == 'add') {
        unset ($tokens[1], $tokens[0]);
        $DF13->tests[$test_key][] = $question = implode(' ', $tokens);
        $message->reply("Added question to test $test_key: $question");
        return $DF13->VarSave('tests.json', $DF13->tests);
    }
    if ($tokens[1] == 'remove') {
        if (! is_numeric($tokens[2])) return $message->replay("Invalid format! Please use the format `tests test_key remove #`");
        if (! isset($DF13->tests[$test_key][$tokens[2]])) return $message->reply("Question not found in test $test_key! Please use the format `tests test_key remove #`");
        $message->reply("Removed question {$tokens[2]}: {$DF13->tests[$test_key][$tokens[2]]}");
        unset($DF13->tests[$test_key][$tokens[2]]);
        return $DF13->VarSave('tests.json', $DF13->tests);
    }
    if ($tokens[1] == 'post') {
        if (! is_numeric($tokens[2])) return $message->replay("Invalid format! Please use the format `tests test_key post #`");
        if (count($DF13->tests[$test_key])<$tokens[2]) return $message->replay("Can't return more questions than exist in a test!");
        $questions = [];
        while (count($questions)<$tokens[2]) if (! in_array($DF13->tests[$test_key][($rand = array_rand($DF13->tests[$test_key]))], $questions)) $questions[] = $DF13->tests[$test_key][$rand];
        return $message->reply("$test_key test:" . PHP_EOL . implode(PHP_EOL, $questions));
    }
    if ($tokens[1] == 'delete') {
        $message->reply("Deleted test `$test_key`");
        unset($DF13->tests[$test_key]);
        return $DF13->VarSave('tests.json', $DF13->tests);
    }
};

$rank_check = function (DF13 $DF13, $message, array $allowed_ranks): bool
{
    $resolved_ranks = [];
    foreach ($allowed_ranks as $rank) $resolved_ranks[] = $DF13->role_ids[$rank];
    foreach ($message->member->roles as $role) if (in_array($role->id, $resolved_ranks)) return true;
    $message->reply('Rejected! You need to have at least the [' . ($message->guild->roles ? $message->guild->roles->get('id', $DF13->role_ids[array_pop($resolved_ranks)])->name : array_pop($allowed_ranks)) . '] rank.');
    return false;
};
$guild_message = function (DF13 $DF13, $message, string $message_content, string $message_content_lower) use ($rank_check, $ban, $unban, $kill, $host, $restart, $mapswap, $log_handler, $banlog_handler, $tests)
{
    if (! $message->member) return $message->reply('Error! Unable to get Discord Member class.');
    
    if (str_starts_with($message_content_lower, 'approveme')) {
        if ($message->member->roles->has($DF13->role_ids['unbearded']) || $message->member->roles->has($DF13->role_ids['bearded'])) return $message->reply('You already have the verification role!');
        if ($item = $DF13->verified->get('discord', $message->member->id)) {
            $message->react("👍");
            return $message->member->setRoles([$DF13->role_ids['unbearded']], "approveme {$item['ss13']}");
        }
        if (! $ckey = str_replace(['.', '_', ' '], '', trim(substr($message_content_lower, 9)))) return $message->reply('Invalid format! Please use the format `approveme ckey`');
        return $message->reply($DF13->verifyProcess($ckey, $message->member->id));
    }

    if (str_starts_with($message_content_lower, 'tests')) {
        if (! $rank_check($DF13, $message, ['thane', 'rune king'])) return $message->react("❌"); 
        return $tests($DF13, $message, trim(substr($message_content, strlen('tests'))));
    }
    
    if (str_starts_with($message_content_lower, 'promotable')) {
        if (! $promotable_check = $DF13->functions['misc']['promotable_check']) return $message->react("🔥");
        if (! $rank_check($DF13, $message, ['thane', 'rune king'])) return $message->react("❌"); 
        if (! $promotable_check($DF13, trim(substr($message_content, 10)))) return $message->react("👎");
        return $message->react("👍");
    }
    
    if (str_starts_with($message_content_lower, 'mass_promotion_loop')) {
        if (! $mass_promotion_loop = $DF13->functions['misc']['mass_promotion_loop']) return $message->react("🔥");
        if (! $rank_check($DF13, $message, ['thane', 'rune king'])) return $message->react("❌"); 
        if (! $mass_promotion_loop($DF13)) return $message->react("👎");
        return $message->react("👍");
    }
    
    if (str_starts_with($message_content_lower, 'mass_promotion_check')) {
        if (! $mass_promotion_check = $DF13->functions['misc']['mass_promotion_check']) return $message->react("🔥");
        if (! $rank_check($DF13, $message, ['thane', 'rune king'])) return $message->react("❌"); 
        if ($promotables = $mass_promotion_check($DF13, $message)) return $message->reply(MessageBuilder::new()->addFileFromContent('promotables.txt', json_encode($promotables)));;
        return $message->react("👎");
    }
    
    if (str_starts_with($message_content_lower, 'whitelistme')) {
        $ckey = str_replace(['.', '_', ' '], '', trim(substr($message_content_lower, 11)));
        if (! $ckey = $DF13->verified->get('discord', $message->member->id)['ss13']) return $message->reply("I didn't find your ckey in the approved list! Please reach out to an administrator.");
        if (! $rank_check($DF13, $message, ['thane', 'rune king', 'longbeard', 'bearded'])) return $message->react("❌");         
        $found = false;
        $whitelist1 = fopen($DF13->files['whitelist'], 'r');
        if ($whitelist1) {
            while (($fp = fgets($whitelist1, 4096)) !== false) foreach (explode(';', trim(str_replace(PHP_EOL, '', $fp))) as $split) if ($split == $ckey) $found = true;
            fclose($whitelist1);
        }
        $whitelist2 = fopen($DF13->files['tdm_whitelist'], 'r');
        if ($whitelist2) {
            while (($fp = fgets($whitelist2, 4096)) !== false) foreach (explode(';', trim(str_replace(PHP_EOL, '', $fp))) as $split) if ($split == $ckey) $found = true;
            fclose($whitelist2);
        }
        if ($found) return $message->reply("$ckey is already in the whitelist!");
        
        $txt = "$ckey = {$message->member->id}" . PHP_EOL;
        if ($whitelist1 = fopen($DF13->files['whitelist'], 'a')) {
            fwrite($whitelist1, $txt);
            fclose($whitelist1);
        }
        if ($whitelist2 = fopen($DF13->files['tdm_whitelist'], 'a')) {
            fwrite($whitelist2, $txt);
            fclose($whitelist2);
        }
        return $message->reply("$ckey has been added to the whitelist.");
    }
    if (str_starts_with($message_content_lower, 'unwhitelistme')) {
        if (! $rank_check($DF13, $message, ['thane', 'rune king', 'longbeard', 'bearded', 'unbearded'])) return $message->react("❌");
        
        $lines_array = array();
        if (! $wlist = fopen($DF13->files['whitelist'], 'r')) return $message->react("🔥");
        while (($fp = fgets($wlist, 4096)) !== false) $lines_array[] = $fp;
        fclose($wlist);
        
        $removed = 'N/A';
        if (count($lines_array) > 0) {
            if (! $wlist = fopen($DF13->files['whitelist'], 'w')) return $message->react("🔥");
            foreach ($lines_array as $line)
                if (!str_contains($line, $message->member->username)) fwrite($wlist, $line);
                else $removed = explode('=', $line)[0];
            fclose($wlist);
        }
        
        $lines_array = array();
        if (! $wlist = fopen($DF13->files['tdm_whitelist'], 'r')) return $message->react("🔥");
        while (($fp = fgets($wlist, 4096)) !== false) $lines_array[] = $fp;
        fclose($wlist);
        
        if (count($lines_array) > 0) {
            if (! $wlist = fopen($DF13->files['tdm_whitelist'], 'w')) return $message->react("🔥");
            foreach ($lines_array as $line)
                if (!str_contains($line, $message->member->username)) fwrite($wlist, $line);
                else $removed = explode('=', $line)[0];
            fclose($wlist);
        }
        return $message->reply("Ckey $removed has been removed from the whitelist.");
    }
    if (str_starts_with($message_content_lower, 'refresh')) {
        if (! $rank_check($DF13, $message, ['thane', 'rune king', 'longbeard'])) return $message->react("❌");
        if ($DF13->getVerified()) return $message->react("👍");
        return $message->react("👎");
    }
    if (str_starts_with($message_content_lower, 'ban ')) {
        if (! $rank_check($DF13, $message, ['thane', 'rune king', 'longbeard'])) return $message->react("❌");
        $message_content = substr($message_content, 4);
        $split_message = explode('; ', $message_content); //$split_target[1] is the target
        if (! $split_message[0]) return $message->reply('Missing ban ckey! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[1]) return $message->reply('Missing ban duration! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[2]) return $message->reply('Missing ban reason! Please use the format `ban ckey; duration; reason`');
        $result = $ban($DF13, $split_message, $message);
        if ($id = $DF13->verified->get('ss13', $split_message[0])['discord'])
            if ($member = $DF13->discord->guilds->get('id', $DF13->DF13_guild_id)->members->get('id', $id)) 
                $member->addRole($DF13->role_ids['banished'], $result);
        return $message->reply($result);
    }
    if (str_starts_with($message_content_lower, 'unban ')) {
        if (! $rank_check($DF13, $message, ['thane', 'rune king', 'longbeard'])) return $message->react("❌");
        $message_content_lower = substr($message_content_lower, 6);
        $split_message = explode('; ', $message_content_lower);
        
        $unban($DF13, $split_message[0], $message->author->displayname);
        $result = "**{$message->author->displayname}** unbanned **{$split_message[0]}**";
        if ($id = $DF13->verified->get('ss13', $split_message[0])['discord'])
            if ($member = $DF13->discord->guilds->get('id', $DF13->DF13_guild_id)->members->get('id', $id)) 
                $member->removeRole($DF13->role_ids['banished'], $result);
        return $message->reply($result);
    }
    if (str_starts_with($message_content_lower, 'unbann ')) {
        if (! $rank_check($DF13, $message, ['thane', 'rune king', 'longbeard'])) return $message->react("❌");
        $message_content_lower = substr($message_content_lower, 6);
        $split_message = explode('; ', $message_content_lower);
        
        $unban($DF13, $split_message[0], $message->author->displayname);
        $result = "**{$message->author->displayname}** unbanned **{$split_message[0]}** from **DF13**";
        if ($id = $DF13->verified->get('ss13', $split_message[0])['discord'])
            if ($member = $DF13->discord->guilds->get('id', $DF13->DF13_guild_id)->members->get('id', $id)) 
                $member->removeRole($DF13->role_ids['banished'], $result);
        return $message->reply($result);
    }
    if (str_starts_with($message_content_lower, 'hostdf')) {
        if (! $rank_check($DF13, $message, ['thane', 'rune king'])) return $message->react("❌");
        $host($DF13);
        return $message->reply("Attempting to update and bring up DF13 <byond://{$DF13->ips['df13']}:{$DF13->ports['df13']}>");
    }
    if (str_starts_with($message_content_lower, 'restartdf')) {
        if (! $rank_check($DF13, $message, ['thane', 'rune king'])) return $message->react("❌");
        $restart($DF13);
        return $message->reply("Attempted to kill, update, and bring up DF13 <byond://{$DF13->ips['df13']}:{$DF13->ports['df13']}>");
    }
    if (str_starts_with($message_content_lower, 'killdf')) {
        if (! $rank_check($DF13, $message, ['thane', 'rune king'])) return $message->react("❌");
        $kill($DF13);
        return $message->reply('Attempted to kill the DF13 server.');
    }
    if (str_starts_with($message_content_lower, 'mapswapdf')) {
        if (! $rank_check($DF13, $message, ['thane', 'rune king'])) return $message->react("❌");
        $split_message = explode('mapswap ', $message_content);
        if (count($split_message) < 2 || !($mapto = strtoupper($split_message[1]))) return $message->reply('You need to include the name of the map.');
        if (! $mapswap($DF13, $mapto, $message)) return $message->reply("$mapto was not found in the map definitions.");
        return $message->reply("Attempting to change map to $mapto");
    }
    if (str_starts_with($message_content_lower, 'maplist')) {
        if (! $rank_check($DF13, $message, ['thane', 'rune king'])) return $message->react("❌");
        return $message->channel->sendFile($DF13->files['map_defines_path'], 'maps.txt');
    }
    if (str_starts_with($message_content_lower, 'banlist')) {
        if (! $rank_check($DF13, $message, ['thane', 'rune king', 'longbeard'])) return $message->react("❌");
        return $message->reply(MessageBuilder::new()->addFile($DF13->files['tdm_bans'], 'bans.txt'));
    }
    if (str_starts_with($message_content_lower, 'logs')) {
        if (! $rank_check($DF13, $message, ['thane', 'rune king', 'longbeard'])) return $message->react("❌");
        if ($log_handler($DF13, $message, trim(substr($message_content, 4)))) return;
    }
    if (str_starts_with($message_content_lower, 'bans')) {
        if (! $rank_check($DF13, $message, ['thane', 'rune king', 'longbeard'])) return $message->react("❌");
        if ($banlog_handler($DF13, $message, trim(substr($message_content_lower, 4)))) return;
    }

    if (str_starts_with($message_content_lower, 'stop')) {
        if ($rank_check($DF13, $message, ['thane', 'rune king'])) return $message->react("❌");
        return $message->react("🛑")->done(function () use ($DF13) { $DF13->stop(); });
    }

    if (str_starts_with($message_content_lower, 'update bans')) {
        if (! $rank_check($DF13, $message, ['thane', 'rune king'])) return $message->react("❌"); 
        if (! $banlogs = file_get_contents($DF13->files['tdm_bans'])) return $message->react("🔥");
        if (! $loglocs = file_get_contents($DF13->files['tdm_playerlogs'])) return $message->react("🔥");
        
        $bans2update = [];
        $oldlist = [];
                
        foreach (explode("|||\n", $banlogs) as $bsplit)
            foreach ($arr = explode(';', $bsplit) as $ban) //position 10 is cid, 11 is ip, starting on 1
                 if ($ban[10] == '0' || $ban[11] == '0') $bans2update[$ban[4]] = $bsplit;
                 else $oldlist[] = $bsplit;
        
        foreach (explode("|||\n", $loglocs) as $lsplit)
            foreach (explode(';', $lsplit) as $log)
                if (isset($bans2update[$log[1]]))
                    foreach ($bans2update as $b2)
                        if($log[1] == $b2[1]) {
                            $bans2update[$log[1]][10] = $log[2];
                            $bans2update[$log[1]][11] = $log[3];
                        }
        file_put_contents($DF13->files['tdm_bans'], implode('|||' . PHP_EOL, array_merge($oldlist, array_values($bans2update))));
        return $message->react("👍");
    }
    
};

$discord2ooc = function (DF13 $DF13, $author, $string): bool
{
    if (! $file = fopen($DF13->files['discord2ooc'], 'a')) return false;
    fwrite($file, "$author:::$string" . PHP_EOL);
    fclose($file);
    return true; 
};
$discord2admin = function (DF13 $DF13, $author, $string): bool
{
    if (! $file = fopen($DF13->files['discord2admin'], 'a')) return false;
    fwrite($file, "$author:::$string" . PHP_EOL);
    fclose($file);
    return true;
};
$discord2dm = function (DF13 $DF13, $author, $string): bool
{
    if (! $file = fopen($DF13->files['discord2dm'], 'a')) return false;
    fwrite($file, "$author:::$string" . PHP_EOL);
    fclose($file);
    return true;
};
$on_message = function (DF13 $DF13, $message) use ($guild_message, $discord2ooc, $discord2admin, $discord2dm)
{ // on message
    if ($message->guild->owner_id != $DF13->owner_id) return; //Only process commands from a guild that Taislin owns
    if (! $DF13->command_symbol) $DF13->command_symbol = '!s';
    
    $message_content = '';
    $message_content_lower = '';
    if (str_starts_with($message->content, $DF13->command_symbol . ' ')) { //Add these as slash commands?
        $message_content = substr($message->content, strlen($DF13->command_symbol)+1);
        $message_content_lower = strtolower($message_content);
    } elseif (str_starts_with($message->content, "<@!{$DF13->discord->id}>")) { //Add these as slash commands?
        $message_content = trim(substr($message->content, strlen($DF13->discord->id)+4));
        $message_content_lower = strtolower($message_content);
    } elseif (str_starts_with($message->content, "<@{$DF13->discord->id}>")) { //Add these as slash commands?
        $message_content = trim(substr($message->content, strlen($DF13->discord->id)+3));
        $message_content_lower = strtolower($message_content);
    }
    if (! $message_content) return;
    
    if (str_starts_with($message_content_lower, 'ping')) return $message->reply('Pong!');
    if (str_starts_with($message_content_lower, 'help')) return $message->reply('**List of Commands**: bancheck, insult, cpu, ping, (un)whitelistme, rankme, ranking. **Staff only**: ban, hostdf13, killdf13, restartdf13, mapswap, hosttdm, killtdm, restarttdm, mapswaptdm');
    if (str_starts_with($message_content_lower, 'cpu')) {
         if (PHP_OS_FAMILY == "Windows") {
            $p = shell_exec('powershell -command "gwmi Win32_PerfFormattedData_PerfOS_Processor | select PercentProcessorTime"');
            $p = preg_replace('/\s+/', ' ', $p); //reduce spaces
            $p = str_replace('PercentProcessorTime', '', $p);
            $p = str_replace('--------------------', '', $p);
            $p = preg_replace('/\s+/', ' ', $p); //reduce spaces
            $load_array = explode(' ', $p);

            $x=0;
            $load = '';
            foreach ($load_array as $line) {
                if (trim($line)) {
                    if ($x==0) {
                        $load = "CPU Usage: $line%" . PHP_EOL;
                        break;
                    } else {
                        //$load = $load . "Core $x: $line%" . PHP_EOL; //No need to report individual cores right now
                    }
                    $x++;
                }
            }
            return $message->reply($load);
        } else { //Linux
            $cpu_load = '-1';
            if ($cpu_load_array = sys_getloadavg()) $cpu_load = array_sum($cpu_load_array) / count($cpu_load_array);
            return $message->reply("CPU Usage: $cpu_load%");
        }
        return $message->reply('Unrecognized operating system!');
    }
    if (str_starts_with($message_content_lower, 'insult')) {
        $split_message = explode(' ', $message_content); //$split_target[1] is the target
        if ((count($split_message) > 1 ) && strlen($split_message[1] > 0)) {
            $incel = $split_message[1];
            $insults_array = array();
            
            if (! $file = fopen($DF13->files['insults_path'], 'r')) return $message->react("🔥");
            while (($fp = fgets($file, 4096)) !== false) $insults_array[] = $fp;
            if (count($insults_array) > 0) {
                $insult = $insults_array[rand(0, count($insults_array)-1)];
                return $message->channel->sendMessage(MessageBuilder::new()->setContent("$incel, $insult")->setAllowedMentions(['parse'=>[]]));
            }
        }
        return;
    }
    if (str_starts_with($message_content_lower, 'ooc ')) {
        $message_filtered = substr($message_content, 4);
        switch (strtolower($message->channel->name)) {
            case 'ooc': 
                if (! $discord2ooc($DF13, $message->author->displayname, $message_filtered)) return $message->react("🔥");
                return $message->react("📧");
            default:
                return $message->reply('You need to be in the #ooc channel to use this command.');
        }
    }
    if (str_starts_with($message_content_lower, 'asay ')) {
        $message_filtered = substr($message_content, 5);
        switch (strtolower($message->channel->name)) {
            case 'ahelp':
                if (! $discord2admin($DF13, $message->author->displayname, $message_filtered)) return $message->react("🔥");
                return $message->react("📧");
            default:
                return $message->reply('You need to be in the #ahelp channel to use this command.');
        }
    }
    if (str_starts_with($message_content_lower, 'dm ') || str_starts_with($message_content_lower, 'pm ')) {
        $split_message = explode(': ', substr($message_content, 3));
        switch (strtolower($message->channel->name)) {
            case 'ahelp':
                if (! $discord2dm($DF13, $message->author->displayname, $split_message)) return $message->react("🔥");
                return $message->react("📧");
            default:
                return $message->reply('You need to be in the #ahelp channel to use this command.');
        }
    }
    if (str_starts_with($message_content_lower, 'bancheck')) {
        if (! $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content_lower, strlen('bancheck'))))) return $message->reply('Wrong format. Please try `bancheck [ckey]`.');
        $reason = "unknown";
        $found = false;
        if ($filecheck1 = fopen($DF13->files['bans'], 'r')) {
            while (($fp = fgets($filecheck1, 4096)) !== false) {
                $linesplit = explode(';', trim(str_replace('|||', '', $fp))); //$split_ckey[0] is the ckey
                if ((count($linesplit)>=8) && ($linesplit[8] == strtolower($ckey))) {
                    $found = true;
                    $type = $linesplit[0];
                    $reason = $linesplit[3];
                    $admin = $linesplit[4];
                    $date = $linesplit[5];
                    $message->reply("**$ckey** has been **$type** banned from **DF13** on **$date** for **$reason** by $admin.");
                }
            }
            fclose($filecheck1);
        }
        if ($filecheck2 = fopen($DF13->files['tdm_bans'], 'r')) {
            while (($fp = fgets($filecheck2, 4096)) !== false) {
                $linesplit = explode(';', trim(str_replace('|||', '', $fp))); //$split_ckey[0] is the ckey
                if ((count($linesplit)>=8) && ($linesplit[8] == strtolower($ckey))) {
                    $found = true;
                    $reason = $linesplit[3];
                    $admin = $linesplit[4];
                    $date = $linesplit[5];
                    $message->reply("**$ckey** has been banned from **TDM** on **$date** for **$reason** by $admin.");
                }
            }
            fclose($filecheck2);
        }
        if (! $found) return $message->reply("No bans were found for **$ckey**.");
        return;
    }
    if (str_starts_with($message_content_lower, 'serverstatus')) { //See GitHub Issue #1
        $embed = new Embed($DF13->discord);
        $_7778 = !\portIsAvailable(7778);
        $server_is_up = ($_7778);
        if (! $server_is_up) {
            $embed->setColor(0x00ff00);
            $embed->addFieldValues('DF13 Server Status', 'Offline');
        } else {
            if ($_7778) {
                if (! $data = file_get_contents($DF13->files['serverdata'])) {
                    $embed->setColor(0x00ff00);
                    $embed->addFieldValues('DF13 Server Status', 'Starting');
                } else {
                    $data = explode(';', str_replace(['<b>Address</b>: ', '<b>Map</b>: ', '<b>Gamemode</b>: ', '<b>Players</b>: ', '</b>', '<b>'], '', $data));
                    $embed->setColor(0x00ff00);
                    $embed->addFieldValues('DF13 Server Status', 'Online');
                    if (isset($data[1])) $embed->addFieldValues('Address', '<'.$data[1].'>');
                    if (isset($data[2])) $embed->addFieldValues('Map', $data[2]);
                    if (isset($data[3])) $embed->addFieldValues('Gamemode', $data[3]);
                    if (isset($data[4])) $embed->addFieldValues('Players', $data[4]);
                }
            } else {
                $embed->setColor(0x00ff00);
                $embed->addFieldValues('DF13 Server Status', 'Offline');
            }
        }
        return $message->channel->sendEmbed($embed);
    }
    if (str_starts_with($message_content_lower, 'discord2ckey')) {
        if (! $item = $DF13->verified->get('discord', $id = trim(str_replace(['<@!', '<@', '>'], '', substr($message_content_lower, strlen('discord2ckey')))))) return $message->reply("`$id` is not registered to any byond username");
        return $message->reply("`$id` is registered to `{$item['ss13']}`");
    }
    if (str_starts_with($message_content_lower, 'ckey2discord')) {
        if (! $item = $DF13->verified->get('ss13', $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content_lower, strlen('discord2ckey')))))) return $message->reply("`$ckey` is not registered to any discord id");
        return $message->reply("`$ckey` is registered to <@{$item['discord']}>");
    }
    if (str_starts_with($message_content_lower, 'ckey')) {
        if (is_numeric($id = trim(str_replace(['<@!', '<@', '>', '.', '_', ' '], '', substr($message_content_lower, strlen('ckey')))))) {
            if (! $item = $DF13->verified->get('discord', $id)) return $message->reply("`$id` is not registered to any ckey");
            if (! $age = $DF13->getByondAge($item['ss13'])) return $message->reply("`{$item['ss13']}` does not exist");
            return $message->reply("`{$item['ss13']}` is registered to <@{$item['discord']}> ($age)");
        }
        if (! $age = $DF13->getByondAge($id)) return $message->reply("`$id` does not exist");
        if ($item = $DF13->verified->get('ss13', $id)) return $message->reply("`{$item['ss13']}` is registered to <@{$item['discord']}> ($age)");
        return $message->reply("`$id` is not registered to any discord id ($age)");
    }
    
    if ($message->member && $guild_message($DF13, $message, $message_content, $message_content_lower)) return;
};

$bancheck = function (DF13 $DF13, string $ckey): bool
{
    $return = false;
    if ($filecheck1 = fopen($DF13->files['bans'], 'r')) {
        while (($fp = fgets($filecheck1, 4096)) !== false) {
            //str_replace(PHP_EOL, '', $fp); // Is this necessary?
            $linesplit = explode(';', trim(str_replace('|||', '', $fp))); //$split_ckey[0] is the ckey
            if ((count($linesplit)>=8) && ($linesplit[8] == $ckey)) $return = true;
        }
        fclose($filecheck1);
    } else $DF13->logger->warning("unable to open `{$DF13->files['bans']}`");
    if ($filecheck2 = fopen($DF13->files['tdm_bans'], 'r')) {
        while (($fp = fgets($filecheck2, 4096)) !== false) {
            //str_replace(PHP_EOL, '', $fp); // Is this necessary?
            $linesplit = explode(';', trim(str_replace('|||', '', $fp))); //$split_ckey[0] is the ckey
            if ((count($linesplit)>=8) && ($linesplit[8] == $ckey)) $return = true;
        }
        fclose($filecheck2);
    } else $DF13->logger->warning("unable to open `{$DF13->files['tdm_bans']}`");
    return $return;
};
$bancheck_join = function (DF13 $DF13, $member) use ($bancheck): void
{ //on GUILD_MEMBER_ADD
    if ($member->guild_id == $DF13->DF13_guild_id) if ($item = $DF13->verified->get('discord', $member->id)) if ($bancheck($DF13, $item['ss13'])) {
        $DF13->discord->getLoop()->addTimer(30, function() use ($DF13, $member, $item) {
            $member->setRoles([$DF13->role_ids['banished']], "bancheck join {$item['ss13']}");
        });
    }
};
$slash_init = function (DF13 $DF13, $commands) use ($bancheck, $unban, $restart_tdm, $restart, $ranking, $rankme, $medals, $brmedals): void
{ //ready_slash
    //if ($command = $commands->get('name', 'ping')) $commands->delete($command->id);
    if (! $commands->get('name', 'ping')) $commands->save(new Command($DF13->discord, [
            'name' => 'ping',
            'description' => 'Replies with Pong!',
    ]));
    
    //if ($command = $commands->get('name', 'restart')) $commands->delete($command->id);
    if (! $commands->get('name', 'restart')) $commands->save(new Command($DF13->discord, [
            'name' => 'restart',
            'description' => 'Restart the bot',
            'dm_permission' => false,
            'default_member_permissions' => (string) new RolePermission($DF13->discord, ['view_audit_log' => true]),
    ]));
    
    //if ($command = $commands->get('name', 'pull')) $commands->delete($command->id);
    if (! $commands->get('name', 'pull')) $commands->save(new Command($DF13->discord, [
            'name' => 'pull',
            'description' => "Update the bot's code",
            'dm_permission' => false,
            'default_member_permissions' => (string) new RolePermission($DF13->discord, ['view_audit_log' => true]),
    ]));
    
    //if ($command = $commands->get('name', 'update')) $commands->delete($command->id);
    if (! $commands->get('name', 'update')) $commands->save(new Command($DF13->discord, [
            'name' => 'update',
            'description' => "Update the bot's dependencies",
            'dm_permission' => false,
            'default_member_permissions' => (string) new RolePermission($DF13->discord, ['view_audit_log' => true]),
    ]));

    //if ($command = $commands->get('name', 'stats')) $commands->delete($command->id);
    if (! $commands->get('name', 'stats')) $commands->save(new Command($DF13->discord, [
        'name' => 'stats',
        'description' => 'Get runtime information about the bot',
        'dm_permission' => false,
        'default_member_permissions' => (string) new RolePermission($DF13->discord, ['moderate_members' => true]),
    ]));
    
    //if ($command = $commands->get('name', 'invite')) $commands->delete($command->id);
    if (! $commands->get('name', 'invite')) $commands->save(new Command($DF13->discord, [
            'name' => 'invite',
            'description' => 'Bot invite link',
            'dm_permission' => false,
            'default_member_permissions' => (string) new RolePermission($DF13->discord, ['manage_guild' => true]),
    ]));
    
    //if ($command = $commands->get('name', 'players')) $commands->delete($command->id);
    if (! $commands->get('name', 'players')) $commands->save(new Command($DF13->discord, [
        'name' => 'players',
        'description' => 'Show Space Station 13 server information'
    ]));
    
    //if ($command = $commands->get('name', 'ckey')) $commands->delete($command->id);
    if (! $commands->get('name', 'ckey')) $commands->save(new Command($DF13->discord, [
        'type' => Command::USER,
        'name' => 'ckey',
        'dm_permission' => false,
        'default_member_permissions' => (string) new RolePermission($DF13->discord, ['moderate_members' => true]),
    ]));
    
     //if ($command = $commands->get('name', 'ckey')) $commands->delete($command->id);
    if (! $commands->get('name', 'bancheck')) $commands->save(new Command($DF13->discord, [
        'type' => Command::USER,
        'name' => 'bancheck',
        'dm_permission' => false,
        'default_member_permissions' => (string) new RolePermission($DF13->discord, ['moderate_members' => true]),
    ]));
    
    //if ($command = $commands->get('name', 'ranking')) $commands->delete($command->id);
    if (! $commands->get('name', 'ranking')) $commands->save(new Command($DF13->discord, [
        'name' => 'ranking',
        'description' => 'See the ranks of the top players on the DF13 server'
    ]));
    
    //if ($command = $commands->get('name', 'ranking')) $commands->delete($command->id);
    if (! $commands->get('name', 'rankme')) $commands->save(new Command($DF13->discord, [
        'name' => 'rankme',
        'description' => 'See your ranking on the DF13 server'
    ]));
    
    //if ($command = $commands->get('name', 'rank')) $commands->delete($command->id);
    if (! $commands->get('name', 'rank')) $commands->save(new Command($DF13->discord, [
        'type' => Command::USER,
        'name' => 'rank',
        'dm_permission' => false,
    ]));
    
    //if ($command = $commands->get('name', 'medals')) $commands->delete($command->id);
    if (! $commands->get('name', 'medals')) $commands->save(new Command($DF13->discord, [
        'type' => Command::USER,
        'name' => 'medals',
        'dm_permission' => false,
    ]));
    
    //if ($command = $commands->get('name', 'brmedals')) $commands->delete($command->id);
    if (! $commands->get('name', 'brmedals')) $commands->save(new Command($DF13->discord, [
        'type' => Command::USER,
        'name' => 'brmedals',
        'dm_permission' => false,
    ]));
    
    $DF13->discord->guilds->get('id', $DF13->DF13_guild_id)->commands->freshen()->done( function ($commands) use ($DF13) {
        //if ($command = $commands->get('name', 'unban')) $commands->delete($command->id);
        if (! $commands->get('name', 'unban')) $commands->save(new Command($DF13->discord, [
            'type' => Command::USER,
            'name' => 'unban',
            'dm_permission' => false,
            'default_member_permissions' => (string) new RolePermission($DF13->discord, ['moderate_members' => true]),
        ]));
        
        //if ($command = $commands->get('name', 'restart')) $commands->delete($command->id);
        if (! $commands->get('name', 'restart')) $commands->save(new Command($DF13->discord, [
            'type' => Command::CHAT_INPUT,
            'name' => 'restart',
            'description' => 'Restart the DF13 server',
            'dm_permission' => false,
            'default_member_permissions' => (string) new RolePermission($DF13->discord, ['view_audit_log' => true]),
        ]));
        
        //if ($command = $commands->get('name', 'restart tdm')) $commands->delete($command->id);
        if (! $commands->get('name', 'restart_tdm')) $commands->save(new Command($DF13->discord, [
            'type' => Command::CHAT_INPUT,
            'name' => 'restart_tdm',
            'description' => 'Restart the TDM server',
            'dm_permission' => false,
            'default_member_permissions' => (string) new RolePermission($DF13->discord, ['view_audit_log' => true]),
        ]));
    });
    
    $DF13->discord->listenCommand('ping', function ($interaction) use ($DF13) {
        $interaction->respondWithMessage(MessageBuilder::new()->setContent('Pong!'));
    });
    
    $DF13->discord->listenCommand('restart', function ($interaction) use ($DF13) {
        $DF13->logger->info('[RESTART]');
        $interaction->respondWithMessage(MessageBuilder::new()->setContent('Restarting...'));
        $DF13->discord->getLoop()->addTimer(5, function () use ($DF13) {
            \restart();
            $DF13->discord->close();
        });
    });
    
    $DF13->discord->listenCommand('pull', function ($interaction) use ($DF13) {
        $DF13->logger->info('[GIT PULL]');
        \execInBackground('git pull');
        $interaction->respondWithMessage(MessageBuilder::new()->setContent('Updating code from GitHub...'));
    });
    
    $DF13->discord->listenCommand('update', function ($interaction) use ($DF13) {
        $DF13->logger->info('[COMPOSER UPDATE]');
        \execInBackground('composer update');
        $interaction->respondWithMessage(MessageBuilder::new()->setContent('Updating dependencies...'));
    });
    
    $DF13->discord->listenCommand('stats', function ($interaction) use ($DF13) {
        $interaction->respondWithMessage(MessageBuilder::new()->setContent('DF13 Stats')->addEmbed($DF13->stats->handle()));
    });
    
    $DF13->discord->listenCommand('invite', function ($interaction) use ($DF13) {
        $interaction->respondWithMessage(MessageBuilder::new()->setContent($DF13->discord->application->getInviteURLAttribute('8')), true);
    });
    
    $DF13->discord->listenCommand('players', function ($interaction) use ($DF13) {
        if (! $data_json = json_decode(file_get_contents("http://{$DF13->ips['vzg']}/servers/serverinfo.json"),  true)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent('Unable to fetch serverinfo.json, webserver might be down'), true);
        $server_info[0] = ['name' => 'TDM', 'host' => 'Taislin', 'link' => "<byond://{$DF13->ips['tdm']}:{$DF13->ports['tdm']}>"];
        $server_info[1] = ['name' => 'DF13', 'host' => 'Taislin', 'link' => "<byond://{$DF13->ips['df13']}:{$DF13->ports['df13']}>"];
        $server_info[2] = ['name' => 'Persistence', 'host' => 'ValZarGaming', 'link' => "<byond://{$DF13->ips['vzg']}:{$DF13->ports['persistence']}>"];
        $server_info[3] = ['name' => 'Blue Colony', 'host' => 'ValZarGaming', 'link' => "<byond://{$DF13->ips['vzg']}:{$DF13->ports['bc']}>"];
        
        $embed = new Embed($DF13->discord);
        foreach ($data_json as $server) {
            $server_info_hard = array_shift($server_info);
            if (array_key_exists('ERROR', $server)) continue;
            if (isset($server_info_hard['name'])) $embed->addFieldValues('Server', $server_info_hard['name'] . PHP_EOL . $server_info_hard['link'], false);
            if (isset($server_info_hard['host'])) $embed->addFieldValues('Host', $server_info_hard['host'], true);
            //Round time
            if (isset($server['roundduration'])) {
                $rd = explode(":", urldecode($server['roundduration']));
                $remainder = ($rd[0] % 24);
                $rd[0] = floor($rd[0] / 24);
                if ($rd[0] != 0 || $remainder != 0 || $rd[1] != 0) $rt = "{$rd[0]}d {$remainder}h {$rd[1]}m";
                else $rt = 'STARTING';
                $embed->addFieldValues('Round Timer', $rt, true);
            }
            if (isset($server['map'])) $embed->addFieldValues('Map', urldecode($server['map']), true);
            if (isset($server['age'])) $embed->addFieldValues('Epoch', urldecode($server['age']), true);
            //Players
            $players = [];
            foreach (array_keys($server) as $key) {
                $p = explode('player', $key); 
                if (isset($p[1]) && is_numeric($p[1])) $players[] = str_replace(['.', '_', ' '], '', strtolower(urldecode($server[$key])));
            }
            if (! empty($players)) $embed->addFieldValues('Players (' . count($players) . ')', implode(', ', $players), true);
            if (isset($server['season'])) $embed->addFieldValues('Season', urldecode($server['season']), true);
        }
        $embed->setFooter(($DF13->github ?  "{$DF13->github}" . PHP_EOL : '') . "{$DF13->discord->username} by Valithor#5947");
        $embed->setColor(0xe1452d);
        $embed->setTimestamp();
        $embed->setURL('');
        return $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($embed));
    });
    
    $DF13->discord->listenCommand('ckey', function ($interaction) use ($DF13) {
        if (! $item = $DF13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        return $interaction->respondWithMessage(MessageBuilder::new()->setContent("`{$interaction->data->target_id}` is registered to `{$item['ss13']}`"), true);
    });
    $DF13->discord->listenCommand('bancheck', function ($interaction) use ($DF13, $bancheck) {
    if (! $item = $DF13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        if ($bancheck($DF13, $item['ss13'])) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("`{$item['ss13']}` is currently banned on one of the DF13.com servers."), true);
        return $interaction->respondWithMessage(MessageBuilder::new()->setContent("`{$item['ss13']}` is not currently banned on one of the DF13.com servers."), true);
    });
    
    $DF13->discord->listenCommand('unban', function ($interaction) use ($DF13, $unban) {
        if (! $item = $DF13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        $interaction->respondWithMessage(MessageBuilder::new()->setContent("**`{$interaction->user->displayname}`** unbanned **`{$item['ss13']}`**."));
        $unban($DF13, $item['ss13'], $interaction->user->displayname);
    });
    
    $DF13->discord->listenCommand('restart', function ($interaction) use ($DF13, $restart) {
    $interaction->respondWithMessage(MessageBuilder::new()->setContent("Attempted to kill, update, and bring up DF13 <byond://{$DF13->ips['tdm']}:{$DF13->ports['tdm']}>"));
        $restart($DF13);
    });
    $DF13->discord->listenCommand('restart_tdm', function ($interaction) use ($DF13, $restart_tdm) {
        $interaction->respondWithMessage(MessageBuilder::new()->setContent("Attempted to kill, update, and bring up TDM <byond://{$DF13->ips['tdm']}:{$DF13->ports['tdm']}>"));
        $restart_tdm($DF13);
    });
    
    $DF13->discord->listenCommand('ranking', function ($interaction) use ($DF13, $ranking) {
        $interaction->respondWithMessage(MessageBuilder::new()->setContent($ranking($DF13)), true);
    });
    $DF13->discord->listenCommand('rankme', function ($interaction) use ($DF13, $rankme) {
        if (! $item = $DF13->verified->get('discord', $interaction->member->id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        $interaction->respondWithMessage(MessageBuilder::new()->setContent($rankme($DF13, $item['ss13'])), true);
    });
    $DF13->discord->listenCommand('rank', function ($interaction) use ($DF13, $rankme) {
        if (! $item = $DF13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        $interaction->respondWithMessage(MessageBuilder::new()->setContent($rankme($DF13, $item['ss13'])), true);
    });
    $DF13->discord->listenCommand('medals', function ($interaction) use ($DF13, $medals) {
        if (! $item = $DF13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        $interaction->respondWithMessage(MessageBuilder::new()->setContent($medals($DF13, $item['ss13'])), true);
    });
    $DF13->discord->listenCommand('brmedals', function ($interaction) use ($DF13, $brmedals) {
        if (! $item = $DF13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        $interaction->respondWithMessage(MessageBuilder::new()->setContent($brmedals($DF13, $item['ss13'])), true);
    });
    /*For deferred interactions
    $DF13->discord->listenCommand('',  function (Interaction $interaction) use ($DF13) {
      // code is expected to be slow, defer the interaction
      $interaction->acknowledge()->done(function () use ($interaction, $DF13) { // wait until the bot says "Is thinking..."
        // do heavy code here (up to 15 minutes)
        // ...
        // send follow up (instead of respond)
        $interaction->sendFollowUpMessage(MessageBuilder...);
      });
    }
    */
};

$ooc_relay = function (DF13 $DF13, string $file_path, $channel) use ($ban): bool
{     
    if (! $file = fopen($file_path, 'r+')) return false;
    while (($fp = fgets($file, 4096)) !== false) {
        $fp = str_replace(PHP_EOL, '', $fp);
        //ban ckey if $fp contains a blacklisted word
        $string = substr($fp, strpos($fp, '/')+1);
        $badwords = ['beaner', 'chink', 'chink', 'coon', 'fag', 'faggot', 'gook', 'kike', 'nigga', 'nigger', 'tranny'];
        $ckey = substr($string, 0, strpos($string, ':'));
        foreach ($badwords as $badword) {
            if (str_contains(strtolower($string), $badword)) {
                $filtered = substr($badword, 0, 1);
                for ($x=1;$x<strlen($badword)-2; $x++) $filtered .= '%';
                $filtered  .= substr($badword, -1, 1);
                $ban($DF13, [$ckey, '999 years', "Blacklisted word ($filtered), please appeal on our discord"]);
            }
        }
        if (! $item = $DF13->verified->get('ss13', strtolower(str_replace(['.', '_', ' '], '', $ckey)))) $channel->sendMessage($fp);
        else {
            $user = $DF13->discord->users->get('id', $item['discord']);
            $embed = new Embed($DF13->discord);
            $embed->setAuthor("{$user->displayname} ({$user->id})", $user->avatar);
            $embed->setDescription($fp);
            $channel->sendEmbed($embed);
        }
    }
    ftruncate($file, 0); //clear the file
    fclose($file);
    return true;
};
$timer_function = function (DF13 $DF13) use ($ooc_relay): void
{
        if ($guild = $DF13->discord->guilds->get('id', $DF13->DF13_guild_id)) { 
        if ($channel = $guild->channels->get('id', $DF13->channel_ids['ooc_channel']))$ooc_relay($DF13, $DF13->files['ooc_path'], $channel);  // #ooc-df13
        if ($channel = $guild->channels->get('id', $DF13->channel_ids['admin_channel'])) $ooc_relay($DF13, $DF13->files['admin_path'], $channel);  // #ahelp-df13
        if ($channel = $guild->channels->get('id', $DF13->channel_ids['tdm_ooc_channel'])) $ooc_relay($DF13, $DF13->files['tdm_ooc_path'], $channel);  // #ooc-tdm
        if ($channel = $guild->channels->get('id', $DF13->channel_ids['tdm_admin_channel'])) $ooc_relay($DF13, $DF13->files['tdm_admin_path'], $channel);  // #ahelp-tdm
    }
};
$on_ready = function (DF13 $DF13) use ($timer_function): void
{//on ready
    $DF13->logger->info("logged in as {$DF13->discord->user->displayname} ({$DF13->discord->id})");
    $DF13->logger->info('------');
    
    if (! (isset($DF13->timers['relay_timer'])) || (! $DF13->timers['relay_timer'] instanceof Timer) ) {
        $DF13->logger->info('chat relay timer started');
        $DF13->timers['relay_timer'] = $DF13->discord->getLoop()->addPeriodicTimer(10, function() use ($timer_function, $DF13) { $timer_function($DF13); });
    }
};