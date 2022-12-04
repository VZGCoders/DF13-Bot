<?php

/*
 * This file is a part of the PS13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */ 

use \PS13\PS13;
use \Discord\Builders\MessageBuilder;
use \Discord\Parts\Embed\Embed;
use \Discord\Parts\User\Activity;
use \Discord\Parts\Interactions\Command\Command;
use \Discord\Parts\Permissions\RolePermission;
use \React\EventLoop\Timer\Timer;
use \React\Promise\ExtendedPromiseInterface;

$set_ips = function (PS13 $PS13): void
{ //on ready
    $civ_ip = gethostbyname('www.civ13.com');
    $external_ip = file_get_contents('http://ipecho.net/plain');
    $PS13->ips = [
        'vzg' => $external_ip,
        'civ13' => $civ_ip,
    ];
    $PS13->ports = [
        'tdm' => '1714',
        'nomads' => '1715',
        'bc' => '1717', 
        'ps13' => '7778',
    ];
};

$status_changer = function ($discord, $activity, $state = 'online'): void
{
    $discord->updatePresence($activity, false, $state);
};
$status_changer_random = function (PS13 $PS13) use ($status_changer): bool
{ //on ready
    if (! $PS13->files['status_path']) {
        unset($PS13->timers['status_changer_timer']);
        $PS13->logger->warning('status_path is not defined');
        return false;
    }
    if (! $status_array = file($PS13->files['status_path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) {
        unset($PS13->timers['status_changer_timer']);
        $PS13->logger->warning("unable to open file `{$PS13->files['status_path']}`");
        return false;
    }
    
    list($status, $type, $state) = explode('; ', $status_array[array_rand($status_array)]);
    if ($status) {
        $activity = new Activity($PS13->discord, [ //Discord status            
            'name' => $status,
            'type' => (int) $type, //0, 1, 2, 3, 4 | Game/Playing, Streaming, Listening, Watching, Custom Status
        ]);
        $status_changer($PS13->discord, $activity, $state);
    }
    return true;
};
$status_changer_timer = function (PS13 $PS13) use ($status_changer_random): void
{ //on ready
    $PS13->timers['status_changer_timer'] = $PS13->discord->getLoop()->addPeriodicTimer(120, function() use ($PS13, $status_changer_random) { $status_changer_random($PS13); });
};

$ban = function (PS13 $PS13, $array, $message = null): string
{ //TODO: add ban to database
    $admin = ($message ? $message->author->displayname : $PS13->discord->user->username);
    $txt = "$admin:::{$array[0]}:::{$array[1]}:::{$array[2]}" . PHP_EOL;
    $result = '';
    if ($file = fopen($PS13->files['discord2ban'], 'a')) {
        fwrite($file, $txt);
        fclose($file);
    } else {
        $PS13->logger->warning("unable to open {$PS13->files['discord2ban']}");
        $result .= "unable to open {$PS13->files['discord2ban']}" . PHP_EOL;
    }
    $result .= "**$admin** banned **{$array[0]}** from **PS13** for **{$array[1]}** with the reason **{$array[2]}**" . PHP_EOL;
    return $result;
};

$unban = function (PS13 $PS13, string $ckey, ?string $admin = null): void
{
    if (! $admin) $admin = $PS13->discord->user->displayname;
    if ($file = fopen($PS13->files['discord2unban'], 'a')) {
        fwrite($file, "$admin:::$ckey");
        fclose($file);
    }
};

$browser_call = function (PS13 $PS13, string $url, string $method = 'GET', array $headers = [], array|string $data = [], $curl = true): false|string|ExtendedPromiseInterface
{
    if (! is_string($data)) $data = http_build_query($data);
    if ( ! $curl && $browser = $PS13->browser) return $browser->{$method}($url, $headers, $data);
    
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

$host = function (PS13 $PS13): void
{
    \execInBackground("python3 {$PS13->files['updateserverabspaths']}");
    \execInBackground("rm -f {$PS13->files['serverdata']}");
    \execInBackground("python3 {$PS13->files['killsudos']}");
    $PS13->discord->getLoop()->addTimer(30, function() use ($PS13) {
        \execInBackground("DreamDaemon {$PS13->files['dmb']} {$PS13->ports['ps13']} -trusted -webclient -logself &");
    });
};
$kill = function (PS13 $PS13): void
{
    \execInBackground("python3 {$PS13->files['killPS13']}");
};
$restart = function (PS13 $PS13) use ($kill, $host): void
{
    $kill($PS13);
    $host($PS13);
};
$mapswap = function (PS13 $PS13, string $mapto): bool
{
    if (! $file = fopen($PS13->files['map_defines_path'], 'r')) return false;
    
    $maps = array();
    while (($fp = fgets($file, 4096)) !== false) {
        $linesplit = explode(' ', trim(str_replace('"', '', $fp)));
        if (isset($linesplit[2]) && $map = trim($linesplit[2])) $maps[] = $map;
    }
    fclose($file);
    if (! in_array($mapto, $maps)) return false;
    
    \execInBackground("python3 {$PS13->files['mapswap_ps13']} $mapto");
    return true;
};

$filenav = function (PS13 $PS13, string $basedir, array $subdirs) use (&$filenav): array
{
    $scandir = scandir($basedir);
    unset($scandir[1], $scandir[0]);
    if (! $subdir = trim(array_shift($subdirs))) return [false, $scandir];
    if (! in_array($subdir, $scandir)) return [false, $scandir, $subdir];
    if (is_file("$basedir/$subdir")) return [true, "$basedir/$subdir"];
    return $filenav($PS13, "$basedir/$subdir", $subdirs);
};
$log_handler = function (PS13 $PS13, $message, string $message_content) use ($filenav)
{
    $tokens = explode(';', $message_content);
    if (!in_array(trim($tokens[0]), ['ps13'])) return $message->reply('Please use the format `logs ps13;folder;file`');
    if (trim($tokens[0]) == 'ps13') {
        unset($tokens[0]);
        $results = $filenav($PS13, $PS13->files['log_basedir'], $tokens);
    }
    if ($results[0]) return $message->reply(MessageBuilder::new()->addFile($results[1], 'log.txt'));
    if (count($results[1]) > 7) $results[1] = [array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1])];
    if (! isset($results[2]) || ! $results[2]) return $message->reply('Available options: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
    return $message->reply("{$results[2]} is not an available option! Available options: " . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
};
$banlog_handler = function (PS13 $PS13, $message, string $message_content_lower)
{
    if (!in_array($message_content_lower, ['ps13'])) return $message->reply('Please use the format `bans ps13`');
    return $message->reply(MessageBuilder::new()->addFile($PS13->files['bans'], 'bans.txt'));
};

$tests = function (PS13 $PS13, $message, string $message_content)
{
    $tokens = explode(' ', $message_content);
    if (! $tokens[0]) {
        if (empty($PS13->tests)) return $message->reply("No tests have been created yet! Try creating one with `tests test_key add {Your Test's Question}`");
        return $message->reply('Available tests: `' . implode('`, `', array_keys($PS13->tests)) . '`');
    }
    if (! isset($tokens[1]) || (! array_key_exists($test_key = $tokens[0], $PS13->tests) && $tokens[1] != 'add')) return $message->reply("Test `$test_key` hasn't been created yet! Please add a question first.");
    if ($tokens[1] == 'list') return $message->reply(MessageBuilder::new()->addFileFromContent("$test_key.txt", var_export($PS13->tests[$test_key], true)));
    if ($tokens[1] == 'add') {
        unset ($tokens[1], $tokens[0]);
        $PS13->tests[$test_key][] = $question = implode(' ', $tokens);
        $message->reply("Added question to test $test_key: $question");
        return $PS13->VarSave('tests.json', $PS13->tests);
    }
    if ($tokens[1] == 'remove') {
        if (! is_numeric($tokens[2])) return $message->replay("Invalid format! Please use the format `tests test_key remove #`");
        if (! isset($PS13->tests[$test_key][$tokens[2]])) return $message->reply("Question not found in test $test_key! Please use the format `tests test_key remove #`");
        $message->reply("Removed question {$tokens[2]}: {$PS13->tests[$test_key][$tokens[2]]}");
        unset($PS13->tests[$test_key][$tokens[2]]);
        return $PS13->VarSave('tests.json', $PS13->tests);
    }
    if ($tokens[1] == 'post') {
        if (! is_numeric($tokens[2])) return $message->replay("Invalid format! Please use the format `tests test_key post #`");
        if (count($PS13->tests[$test_key])<$tokens[2]) return $message->replay("Can't return more questions than exist in a test!");
        $questions = [];
        while (count($questions)<$tokens[2]) if (! in_array($PS13->tests[$test_key][($rand = array_rand($PS13->tests[$test_key]))], $questions)) $questions[] = $PS13->tests[$test_key][$rand];
        return $message->reply("$test_key test:" . PHP_EOL . implode(PHP_EOL, $questions));
    }
    if ($tokens[1] == 'delete') {
        $message->reply("Deleted test `$test_key`");
        unset($PS13->tests[$test_key]);
        return $PS13->VarSave('tests.json', $PS13->tests);
    }
};

$rank_check = function (PS13 $PS13, $message, array $allowed_ranks): bool
{
    $resolved_ranks = [];
    foreach ($allowed_ranks as $rank) $resolved_ranks[] = $PS13->role_ids[$rank];
    foreach ($message->member->roles as $role) if (in_array($role->id, $resolved_ranks)) return true;
    $message->reply('Rejected! You need to have at least the [' . ($message->guild->roles ? $message->guild->roles->get('id', $PS13->role_ids[array_pop($resolved_ranks)])->name : array_pop($allowed_ranks)) . '] rank.');
    return false;
};
$guild_message = function (PS13 $PS13, $message, string $message_content, string $message_content_lower) use ($rank_check, $ban, $unban, $kill, $host, $restart, $mapswap, $log_handler, $banlog_handler, $tests)
{
    if (! $message->member) return $message->reply('Error! Unable to get Discord Member class.');
    
    if (str_starts_with($message_content_lower, 'approveme')) {
        if ($message->member->roles->has($PS13->role_ids['unbearded']) || $message->member->roles->has($PS13->role_ids['bearded'])) return $message->reply('You already have the verification role!');
        if ($item = $PS13->verified->get('discord', $message->member->id)) {
            $message->react("👍");
            return $message->member->setRoles([$PS13->role_ids['unbearded']], "approveme {$item['ss13']}");
        }
        if (! $ckey = str_replace(['.', '_', ' '], '', trim(substr($message_content_lower, 9)))) return $message->reply('Invalid format! Please use the format `approveme ckey`');
        return $message->reply($PS13->verifyProcess($ckey, $message->member->id));
    }

    if (str_starts_with($message_content_lower, 'tests')) {
        if (! $rank_check($PS13, $message, ['thane', 'rune king'])) return $message->react("❌"); 
        return $tests($PS13, $message, trim(substr($message_content, strlen('tests'))));
    }
    
    if (str_starts_with($message_content_lower, 'promotable')) {
        if (! $promotable_check = $PS13->functions['misc']['promotable_check']) return $message->react("🔥");
        if (! $rank_check($PS13, $message, ['thane', 'rune king'])) return $message->react("❌"); 
        if (! $promotable_check($PS13, trim(substr($message_content, 10)))) return $message->react("👎");
        return $message->react("👍");
    }
    
    if (str_starts_with($message_content_lower, 'mass_promotion_loop')) {
        if (! $mass_promotion_loop = $PS13->functions['misc']['mass_promotion_loop']) return $message->react("🔥");
        if (! $rank_check($PS13, $message, ['thane', 'rune king'])) return $message->react("❌"); 
        if (! $mass_promotion_loop($PS13)) return $message->react("👎");
        return $message->react("👍");
    }
    
    if (str_starts_with($message_content_lower, 'mass_promotion_check')) {
        if (! $mass_promotion_check = $PS13->functions['misc']['mass_promotion_check']) return $message->react("🔥");
        if (! $rank_check($PS13, $message, ['thane', 'rune king'])) return $message->react("❌"); 
        if ($promotables = $mass_promotion_check($PS13, $message)) return $message->reply(MessageBuilder::new()->addFileFromContent('promotables.txt', json_encode($promotables)));;
        return $message->react("👎");
    }
    
    if (str_starts_with($message_content_lower, 'whitelistme')) {
        $ckey = str_replace(['.', '_', ' '], '', trim(substr($message_content_lower, 11)));
        if (! $ckey = $PS13->verified->get('discord', $message->member->id)['ss13']) return $message->reply("I didn't find your ckey in the approved list! Please reach out to an administrator.");
        if (! $rank_check($PS13, $message, ['thane', 'rune king', 'longbeard', 'bearded'])) return $message->react("❌");         
        $found = false;
        $whitelist1 = fopen($PS13->files['whitelist'], 'r');
        if ($whitelist1) {
            while (($fp = fgets($whitelist1, 4096)) !== false) foreach (explode(';', trim(str_replace(PHP_EOL, '', $fp))) as $split) if ($split == $ckey) $found = true;
            fclose($whitelist1);
        }
        if ($found) return $message->reply("$ckey is already in the whitelist!");
        
        $txt = "$ckey = {$message->member->id}" . PHP_EOL;
        if ($whitelist1 = fopen($PS13->files['whitelist'], 'a')) {
            fwrite($whitelist1, $txt);
            fclose($whitelist1);
        }
        return $message->reply("$ckey has been added to the whitelist.");
    }
    if (str_starts_with($message_content_lower, 'unwhitelistme')) {
        if (! $rank_check($PS13, $message, ['thane', 'rune king', 'longbeard', 'bearded', 'unbearded'])) return $message->react("❌");
        
        $lines_array = array();
        if (! $wlist = fopen($PS13->files['whitelist'], 'r')) return $message->react("🔥");
        while (($fp = fgets($wlist, 4096)) !== false) $lines_array[] = $fp;
        fclose($wlist);
        
        $removed = 'N/A';
        if (count($lines_array) > 0) {
            if (! $wlist = fopen($PS13->files['whitelist'], 'w')) return $message->react("🔥");
            foreach ($lines_array as $line)
                if (!str_contains($line, $message->member->username)) fwrite($wlist, $line);
                else $removed = explode('=', $line)[0];
            fclose($wlist);
        }
        return $message->reply("Ckey $removed has been removed from the whitelist.");
    }
    if (str_starts_with($message_content_lower, 'refresh')) {
        if (! $rank_check($PS13, $message, ['thane', 'rune king', 'longbeard'])) return $message->react("❌");
        if ($PS13->getVerified()) return $message->react("👍");
        return $message->react("👎");
    }
    if (str_starts_with($message_content_lower, 'ban ')) {
        if (! $rank_check($PS13, $message, ['thane', 'rune king', 'longbeard'])) return $message->react("❌");
        $message_content = substr($message_content, 4);
        $split_message = explode('; ', $message_content); //$split_target[1] is the target
        if (! $split_message[0]) return $message->reply('Missing ban ckey! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[1]) return $message->reply('Missing ban duration! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[2]) return $message->reply('Missing ban reason! Please use the format `ban ckey; duration; reason`');
        $result = $ban($PS13, $split_message, $message);
        if ($id = $PS13->verified->get('ss13', $split_message[0])['discord'])
            if ($member = $PS13->discord->guilds->get('id', $PS13->PS13_guild_id)->members->get('id', $id)) 
                $member->addRole($PS13->role_ids['banished'], $result);
        return $message->reply($result);
    }
    if (str_starts_with($message_content_lower, 'unban ')) {
        if (! $rank_check($PS13, $message, ['thane', 'rune king', 'longbeard'])) return $message->react("❌");
        $message_content_lower = substr($message_content_lower, 6);
        $split_message = explode('; ', $message_content_lower);
        
        $unban($PS13, $split_message[0], $message->author->displayname);
        $result = "**{$message->author->displayname}** unbanned **{$split_message[0]}**";
        if ($id = $PS13->verified->get('ss13', $split_message[0])['discord'])
            if ($member = $PS13->discord->guilds->get('id', $PS13->PS13_guild_id)->members->get('id', $id)) 
                $member->removeRole($PS13->role_ids['banished'], $result);
        return $message->reply($result);
    }
    if (str_starts_with($message_content_lower, 'unbann ')) {
        if (! $rank_check($PS13, $message, ['thane', 'rune king', 'longbeard'])) return $message->react("❌");
        $message_content_lower = substr($message_content_lower, 6);
        $split_message = explode('; ', $message_content_lower);
        
        $unban($PS13, $split_message[0], $message->author->displayname);
        $result = "**{$message->author->displayname}** unbanned **{$split_message[0]}** from **PS13**";
        if ($id = $PS13->verified->get('ss13', $split_message[0])['discord'])
            if ($member = $PS13->discord->guilds->get('id', $PS13->PS13_guild_id)->members->get('id', $id)) 
                $member->removeRole($PS13->role_ids['banished'], $result);
        return $message->reply($result);
    }
    if (str_starts_with($message_content_lower, 'hostdf')) {
        if (! $rank_check($PS13, $message, ['thane', 'rune king'])) return $message->react("❌");
        $host($PS13);
        return $message->reply("Attempting to update and bring up PS13 <byond://{$PS13->ips['ps13']}:{$PS13->ports['ps13']}>");
    }
    if (str_starts_with($message_content_lower, 'restartdf')) {
        if (! $rank_check($PS13, $message, ['thane', 'rune king'])) return $message->react("❌");
        $restart($PS13);
        return $message->reply("Attempted to kill, update, and bring up PS13 <byond://{$PS13->ips['ps13']}:{$PS13->ports['ps13']}>");
    }
    if (str_starts_with($message_content_lower, 'killdf')) {
        if (! $rank_check($PS13, $message, ['thane', 'rune king'])) return $message->react("❌");
        $kill($PS13);
        return $message->reply('Attempted to kill the PS13 server.');
    }
    if (str_starts_with($message_content_lower, 'mapswapdf')) {
        if (! $rank_check($PS13, $message, ['thane', 'rune king'])) return $message->react("❌");
        $split_message = explode('mapswap ', $message_content);
        if (count($split_message) < 2 || !($mapto = strtoupper($split_message[1]))) return $message->reply('You need to include the name of the map.');
        if (! $mapswap($PS13, $mapto, $message)) return $message->reply("$mapto was not found in the map definitions.");
        return $message->reply("Attempting to change map to $mapto");
    }
    if (str_starts_with($message_content_lower, 'maplist')) {
        if (! $rank_check($PS13, $message, ['thane', 'rune king'])) return $message->react("❌");
        return $message->channel->sendFile($PS13->files['map_defines_path'], 'maps.txt');
    }
    if (str_starts_with($message_content_lower, 'banlist')) {
        if (! $rank_check($PS13, $message, ['thane', 'rune king', 'longbeard'])) return $message->react("❌");
        return $message->reply(MessageBuilder::new()->addFile($PS13->files['bans'], 'bans.txt'));
    }
    if (str_starts_with($message_content_lower, 'logs')) {
        if (! $rank_check($PS13, $message, ['thane', 'rune king', 'longbeard'])) return $message->react("❌");
        if ($log_handler($PS13, $message, trim(substr($message_content, 4)))) return;
    }
    if (str_starts_with($message_content_lower, 'bans')) {
        if (! $rank_check($PS13, $message, ['thane', 'rune king', 'longbeard'])) return $message->react("❌");
        if ($banlog_handler($PS13, $message, trim(substr($message_content_lower, 4)))) return;
    }

    if (str_starts_with($message_content_lower, 'stop')) {
        if ($rank_check($PS13, $message, ['thane', 'rune king'])) return $message->react("❌");
        return $message->react("🛑")->done(function () use ($PS13) { $PS13->stop(); });
    }

    if (str_starts_with($message_content_lower, 'update bans')) {
        if (! $rank_check($PS13, $message, ['thane', 'rune king'])) return $message->react("❌"); 
        if (! $banlogs = file_get_contents($PS13->files['bans'])) return $message->react("🔥");
        if (! $loglocs = file_get_contents($PS13->files['playerlogs'])) return $message->react("🔥");
        
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
        file_put_contents($PS13->files['bans'], implode('|||' . PHP_EOL, array_merge($oldlist, array_values($bans2update))));
        return $message->react("👍");
    }
    
};

$bancheck = function (PS13 $PS13, string $ckey): bool
{
    if (! $data_json = json_decode(file_get_contents("http://ps13.valzargaming.com/sql/?method=bans"),  true) ?? $PS13->bans) return false;
    $PS13->bans = $data_json;
    $return = false;
    $PS13->bancheck_temp = [];
    foreach ($PS13->bans = $data_json as $ban) if ($ban['ckey'] == $ckey) {
        $return = true;
        $PS13->bancheck_temp[] = $ban;
    }
    return $return;
};
$join_roles = function (PS13 $PS13, $member) use ($bancheck)
{
    if ($member->guild_id != $PS13->PS13_guild_id) return;
    if ($item = $PS13->verified->get('discord', $member->id)) {
        if (! $bancheck($PS13, $item['ss13'])) return $member->setroles([$PS13->role_ids['unbearded']], "verified join {$item['ss13']}");
        return $member->setroles([$PS13->role_ids['unbearded'], $PS13->role_ids['banished']], "bancheck join {$item['ss13']}");
    }
};

$discord2ooc = function (PS13 $PS13, $author, $string): bool
{
    if (! $file = fopen($PS13->files['discord2ooc'], 'a')) return false;
    fwrite($file, "$author:::$string" . PHP_EOL);
    fclose($file);
    return true; 
};
$discord2admin = function (PS13 $PS13, $author, $string): bool
{
    if (! $file = fopen($PS13->files['discord2admin'], 'a')) return false;
    fwrite($file, "$author:::$string" . PHP_EOL);
    fclose($file);
    return true;
};
$discord2dm = function (PS13 $PS13, $author, $string): bool
{
    if (! $file = fopen($PS13->files['discord2dm'], 'a')) return false;
    fwrite($file, "$author:::$string" . PHP_EOL);
    fclose($file);
    return true;
};
$on_message = function (PS13 $PS13, $message) use ($guild_message, $bancheck, $discord2ooc, $discord2admin, $discord2dm)
{ // on message
    if ($message->guild->owner_id != $PS13->owner_id) return; //Only process commands from a guild that Taislin owns
    if (! $PS13->command_symbol) $PS13->command_symbol = '!s';
    
    $message_content = '';
    $message_content_lower = '';
    if (str_starts_with($message->content, $PS13->command_symbol . ' ')) { //Add these as slash commands?
        $message_content = substr($message->content, strlen($PS13->command_symbol)+1);
        $message_content_lower = strtolower($message_content);
    } elseif (str_starts_with($message->content, "<@!{$PS13->discord->id}>")) { //Add these as slash commands?
        $message_content = trim(substr($message->content, strlen($PS13->discord->id)+4));
        $message_content_lower = strtolower($message_content);
    } elseif (str_starts_with($message->content, "<@{$PS13->discord->id}>")) { //Add these as slash commands?
        $message_content = trim(substr($message->content, strlen($PS13->discord->id)+3));
        $message_content_lower = strtolower($message_content);
    }
    if (! $message_content) return;
    
    if (str_starts_with($message_content_lower, 'ping')) return $message->reply('Pong!');
    if (str_starts_with($message_content_lower, 'help')) return $message->reply('**List of Commands**: bancheck, insult, cpu, ping, (un)whitelistme, rankme, ranking. **Staff only**: ban, hostps13, killps13, restartps13, mapswap, hostdf, killdf, restartdf, mapswapdf');
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
            
            if (! $file = fopen($PS13->files['insults_path'], 'r')) return $message->react("🔥");
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
                if (! $discord2ooc($PS13, $message->author->displayname, $message_filtered)) return $message->react("🔥");
                return $message->react("📧");
            default:
                return $message->reply('You need to be in the #ooc channel to use this command.');
        }
    }
    if (str_starts_with($message_content_lower, 'asay ')) {
        $message_filtered = substr($message_content, 5);
        switch (strtolower($message->channel->name)) {
            case 'ahelp':
                if (! $discord2admin($PS13, $message->author->displayname, $message_filtered)) return $message->react("🔥");
                return $message->react("📧");
            default:
                return $message->reply('You need to be in the #ahelp channel to use this command.');
        }
    }
    if (str_starts_with($message_content_lower, 'dm ') || str_starts_with($message_content_lower, 'pm ')) {
        $split_message = explode(': ', substr($message_content, 3));
        switch (strtolower($message->channel->name)) {
            case 'ahelp':
                if (! $discord2dm($PS13, $message->author->displayname, $split_message)) return $message->react("🔥");
                return $message->react("📧");
            default:
                return $message->reply('You need to be in the #ahelp channel to use this command.');
        }
    }
    if (str_starts_with($message_content_lower, 'bancheck')) {
        if (! $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content_lower, strlen('bancheck'))))) return $message->reply('Wrong format. Please try `bancheck [ckey]`.');
        if (! $bancheck($PS13, $ckey)) return $message->reply("No bans were found for **$ckey**.");
        $return[] = "`$ckey` has been banned:";
        foreach ($PS13->bancheck_temp as $ban) $return[] = "from **{$ban['role']}** by `{$ban['a_ckey']}` for **{$ban['reason']}** and expires **" . ($ban['expiration_time'] ? date("D M j G:i:s T Y", $ban['expiration_time']) . '**' : 'never**');
        return $message->reply(implode(PHP_EOL, $return));
    }
    if (str_starts_with($message_content_lower, 'serverstatus')) { //See GitHub Issue #1
        $embed = new Embed($PS13->discord);
        $_7778 = !\portIsAvailable(7778);
        $server_is_up = ($_7778);
        if (! $server_is_up) {
            $embed->setColor(0x00ff00);
            $embed->addFieldValues('PS13 Server Status', 'Offline');
        } else {
            if ($_7778) {
                if (! $data = file_get_contents($PS13->files['serverdata'])) {
                    $embed->setColor(0x00ff00);
                    $embed->addFieldValues('PS13 Server Status', 'Starting');
                } else {
                    $data = explode(';', str_replace(['<b>Address</b>: ', '<b>Map</b>: ', '<b>Gamemode</b>: ', '<b>Players</b>: ', '</b>', '<b>'], '', $data));
                    $embed->setColor(0x00ff00);
                    $embed->addFieldValues('PS13 Server Status', 'Online');
                    if (isset($data[1])) $embed->addFieldValues('Address', "<{$data[1]}>");
                    if (isset($data[2])) $embed->addFieldValues('Map', $data[2]);
                    if (isset($data[3])) $embed->addFieldValues('Gamemode', $data[3]);
                    if (isset($data[4])) $embed->addFieldValues('Players', $data[4]);
                }
            } else {
                $embed->setColor(0x00ff00);
                $embed->addFieldValues('PS13 Server Status', 'Offline');
            }
        }
        return $message->channel->sendEmbed($embed);
    }
    if (str_starts_with($message_content_lower, 'discord2ckey')) {
        if (! $item = $PS13->verified->get('discord', $id = trim(str_replace(['<@!', '<@', '>'], '', substr($message_content_lower, strlen('discord2ckey')))))) return $message->reply("`$id` is not registered to any byond username");
        return $message->reply("`$id` is registered to `{$item['ss13']}`");
    }
    if (str_starts_with($message_content_lower, 'ckey2discord')) {
        if (! $item = $PS13->verified->get('ss13', $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content_lower, strlen('discord2ckey')))))) return $message->reply("`$ckey` is not registered to any discord id");
        return $message->reply("`$ckey` is registered to <@{$item['discord']}>");
    }
    if (str_starts_with($message_content_lower, 'ckey')) {
        if (is_numeric($id = trim(str_replace(['<@!', '<@', '>', '.', '_', ' '], '', substr($message_content_lower, strlen('ckey')))))) {
            if (! $item = $PS13->verified->get('discord', $id)) return $message->reply("`$id` is not registered to any ckey");
            if (! $age = $PS13->getByondAge($item['ss13'])) return $message->reply("`{$item['ss13']}` does not exist");
            return $message->reply("`{$item['ss13']}` is registered to <@{$item['discord']}> ($age)");
        }
        if (! $age = $PS13->getByondAge($id)) return $message->reply("`$id` does not exist");
        if ($item = $PS13->verified->get('ss13', $id)) return $message->reply("`{$item['ss13']}` is registered to <@{$item['discord']}> ($age)");
        return $message->reply("`$id` is not registered to any discord id ($age)");
    }
    
    if ($message->member && $guild_message($PS13, $message, $message_content, $message_content_lower)) return;
};

$serverinfo_players = function ($PS13): array
{
    if (empty($data_json = $PS13->serverinfo)) return [];
    $PS13->players = [];
    foreach ($data_json as $server) {
        if (array_key_exists('ERROR', $server)) continue;
        //Players
        foreach (array_keys($server) as $key) {
            $p = explode('player', $key); 
            if (isset($p[1]) && is_numeric($p[1])) $PS13->players[] = str_replace(['.', '_', ' '], '', strtolower(urldecode($server[$key])));
        }
    }
    return $PS13->players;
};
$playercount_channel_update = function ($PS13, $count = 0): void
{
    if ($channel = $PS13->discord->getChannel($PS13->channel_ids['playercount']))
        if (substr($channel->name, 8) != $count) {
            $channel->name = "players-$count";
            $channel->guild->channels->save($channel);
        }
};
$serverinfo_fetch = function ($PS13): array
{
    if (! $data_json = json_decode(file_get_contents("http://{$PS13->ips['vzg']}/servers/serverinfo.json"),  true)) return [];
    return $PS13->serverinfo = $data_json;
};
$serverinfo_timer = function ($PS13) use ($serverinfo_fetch/*, $serverinfo_players*/): void
{ //TODO: Add automatic banning of new accounts
    $serverinfo_fetch($PS13);
    $PS13->timers['serverinfo_timer'] = $PS13->discord->getLoop()->addPeriodicTimer(60, function() use ($PS13, $serverinfo_fetch) { $serverinfo_fetch($PS13); });
};
$serverinfo_parse = function ($PS13) use ($playercount_channel_update): array
{
    if (empty($data_json = $PS13->serverinfo)) return [];
    $return = [];

    $server_info[0] = ['name' => 'TDM', 'host' => 'Taislin', 'link' => "<byond://{$PS13->ips['civ13']}:{$PS13->ports['tdm']}>"];
    $server_info[1] = ['name' => 'Nomads', 'host' => 'Taislin', 'link' => "<byond://{$PS13->ips['civ13']}:{$PS13->ports['nomads']}>"];
    $server_info[2] = ['name' => 'Blue Colony', 'host' => 'ValZarGaming', 'link' => "<byond://{$PS13->ips['vzg']}:{$PS13->ports['bc']}>"];
    $server_info[3] = ['name' => 'Pocket Stronghold 13', 'host' => 'ValZarGaming', 'link' => "<byond://{$PS13->ips['vzg']}:{$PS13->ports['ps13']}>"];
    
    $index = 0;
    foreach ($data_json as $server) {
        $server_info_hard = array_shift($server_info);
        if (array_key_exists('ERROR', $server)) {
            $index++;
            continue;
        }
        if (isset($server_info_hard['name'])) $return[$index]['Server'] = [false => $server_info_hard['name'] . PHP_EOL . $server_info_hard['link']];
        if (isset($server_info_hard['host'])) $return[$index]['Host'] = [true => $server_info_hard['host']];
        //Round time
        if (isset($server['roundduration']) /*|| isset($server['round_duration'])*/) { //TODO
            $rd = explode(":", urldecode($server['roundduration']));
            $remainder = ($rd[0] % 24);
            $rd[0] = floor($rd[0] / 24);
            if ($rd[0] != 0 || $remainder != 0 || $rd[1] != 0) $rt = "{$rd[0]}d {$remainder}h {$rd[1]}m";
            else $rt = 'STARTING';
            $return[$index]['Round Timer'] = [true => $rt];
        }
        if (isset($server['round_duration'])) {
            //TODO
        }
        if (isset($server['map'])) $return[$index]['Map'] = [true => urldecode($server['map'])];
        if (isset($server['age'])) $return[$index]['Epoch'] = [true => urldecode($server['age'])];
        //Players
        $players = [];
        foreach (array_keys($server) as $key) {
            $p = explode('player', $key); 
            if (isset($p[1])) if(is_numeric($p[1])) $players[] = str_replace(['.', '_', ' '], '', strtolower(urldecode($server[$key])));
        }
        if ($index == 3) $playercount_channel_update($PS13, (isset($server['players']) ? $server['players'] : count($players) ?? 0));
        if ($server['players'] || ! empty($players)) $return[$index]['Players (' . (isset($server['players']) ? $server['players'] : count($players) ?? '?') . ')'] = [true => (empty($players) ? 'N/A' : implode(', ', $players))];
        if (isset($server['season'])) $return[$index]['Season'] = [true => urldecode($server['season'])];
        $index++;
    }
    return $return;
};

$slash_init = function (PS13 $PS13, $commands) use ($bancheck, $unban, $restart, $serverinfo_parse): void
{ //ready_slash
    //if ($command = $commands->get('name', 'ping')) $commands->delete($command->id);
    if (! $commands->get('name', 'ping')) $commands->save(new Command($PS13->discord, [
            'name' => 'ping',
            'description' => 'Replies with Pong!',
    ]));
    
    //if ($command = $commands->get('name', 'restart')) $commands->delete($command->id);
    if (! $commands->get('name', 'restart')) $commands->save(new Command($PS13->discord, [
            'name' => 'restart',
            'description' => 'Restart the bot',
            'dm_permission' => false,
            'default_member_permissions' => (string) new RolePermission($PS13->discord, ['view_audit_log' => true]),
    ]));
    
    //if ($command = $commands->get('name', 'pull')) $commands->delete($command->id);
    if (! $commands->get('name', 'pull')) $commands->save(new Command($PS13->discord, [
            'name' => 'pull',
            'description' => "Update the bot's code",
            'dm_permission' => false,
            'default_member_permissions' => (string) new RolePermission($PS13->discord, ['view_audit_log' => true]),
    ]));
    
    //if ($command = $commands->get('name', 'update')) $commands->delete($command->id);
    if (! $commands->get('name', 'update')) $commands->save(new Command($PS13->discord, [
            'name' => 'update',
            'description' => "Update the bot's dependencies",
            'dm_permission' => false,
            'default_member_permissions' => (string) new RolePermission($PS13->discord, ['view_audit_log' => true]),
    ]));

    //if ($command = $commands->get('name', 'stats')) $commands->delete($command->id);
    if (! $commands->get('name', 'stats')) $commands->save(new Command($PS13->discord, [
        'name' => 'stats',
        'description' => 'Get runtime information about the bot',
        'dm_permission' => false,
        'default_member_permissions' => (string) new RolePermission($PS13->discord, ['moderate_members' => true]),
    ]));
    
    //if ($command = $commands->get('name', 'invite')) $commands->delete($command->id);
    if (! $commands->get('name', 'invite')) $commands->save(new Command($PS13->discord, [
            'name' => 'invite',
            'description' => 'Bot invite link',
            'dm_permission' => false,
            'default_member_permissions' => (string) new RolePermission($PS13->discord, ['manage_guild' => true]),
    ]));
    
    //if ($command = $commands->get('name', 'players')) $commands->delete($command->id);
    if (! $commands->get('name', 'players')) $commands->save(new Command($PS13->discord, [
        'name' => 'players',
        'description' => 'Show Space Station 13 server information'
    ]));
    
    //if ($command = $commands->get('name', 'ckey')) $commands->delete($command->id);
    if (! $commands->get('name', 'ckey')) $commands->save(new Command($PS13->discord, [
        'type' => Command::USER,
        'name' => 'ckey',
        'dm_permission' => false,
        'default_member_permissions' => (string) new RolePermission($PS13->discord, ['moderate_members' => true]),
    ]));
    
     //if ($command = $commands->get('name', 'ckey')) $commands->delete($command->id);
    if (! $commands->get('name', 'bancheck')) $commands->save(new Command($PS13->discord, [
        'type' => Command::USER,
        'name' => 'bancheck',
        'dm_permission' => false,
        'default_member_permissions' => (string) new RolePermission($PS13->discord, ['moderate_members' => true]),
    ]));
    
    $PS13->discord->guilds->get('id', $PS13->PS13_guild_id)->commands->freshen()->done( function ($commands) use ($PS13) {
        //if ($command = $commands->get('name', 'unban')) $commands->delete($command->id);
        if (! $commands->get('name', 'unban')) $commands->save(new Command($PS13->discord, [
            'type' => Command::USER,
            'name' => 'unban',
            'dm_permission' => false,
            'default_member_permissions' => (string) new RolePermission($PS13->discord, ['moderate_members' => true]),
        ]));
        
        //if ($command = $commands->get('name', 'restartdf')) $commands->delete($command->id);
        if (! $commands->get('name', 'restartdf')) $commands->save(new Command($PS13->discord, [
            'type' => Command::CHAT_INPUT,
            'name' => 'restartdf',
            'description' => 'Restart the PS13 server',
            'dm_permission' => false,
            'default_member_permissions' => (string) new RolePermission($PS13->discord, ['view_audit_log' => true]),
        ]));
    });
    
    $PS13->discord->listenCommand('ping', function ($interaction) use ($PS13) {
        $interaction->respondWithMessage(MessageBuilder::new()->setContent('Pong!'));
    });
    
    $PS13->discord->listenCommand('restart', function ($interaction) use ($PS13) {
        $PS13->logger->info('[RESTART]');
        $interaction->respondWithMessage(MessageBuilder::new()->setContent('Restarting...'));
        $PS13->discord->getLoop()->addTimer(5, function () use ($PS13) {
            \restart();
            $PS13->discord->close();
        });
    });
    
    $PS13->discord->listenCommand('pull', function ($interaction) use ($PS13) {
        $PS13->logger->info('[GIT PULL]');
        \execInBackground('git pull');
        $interaction->respondWithMessage(MessageBuilder::new()->setContent('Updating code from GitHub...'));
    });
    
    $PS13->discord->listenCommand('update', function ($interaction) use ($PS13) {
        $PS13->logger->info('[COMPOSER UPDATE]');
        \execInBackground('composer update');
        $interaction->respondWithMessage(MessageBuilder::new()->setContent('Updating dependencies...'));
    });
    
    $PS13->discord->listenCommand('stats', function ($interaction) use ($PS13) {
        $interaction->respondWithMessage(MessageBuilder::new()->setContent('PS13 Stats')->addEmbed($PS13->stats->handle()));
    });
    
    $PS13->discord->listenCommand('invite', function ($interaction) use ($PS13) {
        $interaction->respondWithMessage(MessageBuilder::new()->setContent($PS13->discord->application->getInviteURLAttribute('8')), true);
    });
    
    $PS13->discord->listenCommand('players', function ($interaction) use ($PS13, $serverinfo_parse) {
        if (empty($data = $serverinfo_parse($PS13))) return $interaction->respondWithMessage(MessageBuilder::new()->setContent('Unable to fetch serverinfo.json, webserver might be down'), true);
        $embed = new Embed($PS13->discord);
        foreach ($data as $server)
             foreach ($server as $key => $array)
                foreach ($array as $inline => $value)
                    $embed->addFieldValues($key, $value, $inline);
        $embed->setFooter(($PS13->github ?  "{$PS13->github}" . PHP_EOL : '') . "{$PS13->discord->username} by Valithor#5947");
        $embed->setColor(0xe1452d);
        $embed->setTimestamp();
        $embed->setURL('');
        return $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($embed));
    });
    
    $PS13->discord->listenCommand('ckey', function ($interaction) use ($PS13) {
        if (! $item = $PS13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        return $interaction->respondWithMessage(MessageBuilder::new()->setContent("`{$interaction->data->target_id}` is registered to `{$item['ss13']}`"), true);
    });
    $PS13->discord->listenCommand('bancheck', function ($interaction) use ($PS13, $bancheck) {
    if (! $item = $PS13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        if ($bancheck($PS13, $item['ss13'])) {
            $return[] = "`{$item['ss13']}` has been banned:";
            foreach ($PS13->bancheck_temp as $ban) $return[] = "from **{$ban['role']}** by `{$ban['adminwho']}` for **{$ban['reason']}** and expires **" . ($ban['adminwho'] ? 'never**' : date("D M j G:i:s T Y", $ban['expires']) . '**');
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent(implode(PHP_EOL, $return)), true);
        }
        return $interaction->respondWithMessage(MessageBuilder::new()->setContent("`{$item['ss13']}` is not currently banned."), true);
    });
    
    $PS13->discord->listenCommand('unban', function ($interaction) use ($PS13, $unban) {
        if (! $item = $PS13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        $interaction->respondWithMessage(MessageBuilder::new()->setContent("**`{$interaction->user->displayname}`** unbanned **`{$item['ss13']}`**."));
        $unban($PS13, $item['ss13'], $interaction->user->displayname);
    });
    
    $PS13->discord->listenCommand('restartdf', function ($interaction) use ($PS13, $restart) {
    $interaction->respondWithMessage(MessageBuilder::new()->setContent("Attempted to kill, update, and bring up PS13 <byond://{$PS13->ips['vzg']}:{$PS13->ports['ps13']}>"));
        $restart($PS13);
    });
    /*For deferred interactions
    $PS13->discord->listenCommand('',  function (Interaction $interaction) use ($PS13) {
      // code is expected to be slow, defer the interaction
      $interaction->acknowledge()->done(function () use ($interaction, $PS13) { // wait until the bot says "Is thinking..."
        // do heavy code here (up to 15 minutes)
        // ...
        // send follow up (instead of respond)
        $interaction->sendFollowUpMessage(MessageBuilder...);
      });
    }
    */
};

$on_ready = function (PS13 $PS13): void
{//on ready
    $PS13->logger->info("logged in as {$PS13->discord->user->displayname} ({$PS13->discord->id})");
    $PS13->logger->info('------');
    
    /* Deprecated
    if (! (isset($PS13->timers['relay_timer'])) || (! $PS13->timers['relay_timer'] instanceof Timer) ) {
        $PS13->logger->info('chat relay timer started');
        //$PS13->timers['relay_timer'] = $PS13->discord->getLoop()->addPeriodicTimer(10, function() use ($timer_function, $PS13) { $timer_function($PS13); }); //PS13 currently uses webhooks
    }
    */
};