<?php

/*
 * This file is a part of the PS13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

use PS13\PS13;
use React\Socket\Server as SocketServer;
use React\Http\Server as HttpServer;
use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;
use Discord\Parts\Embed\Embed;

function webapiFail($part, $id) {
    //logInfo('[webapi] Failed', ['part' => $part, 'id' => $id]);
    return new Response(($id ? 404 : 400), ['Content-Type' => 'text/plain'], ($id ? 'Invalid' : 'Missing').' '.$part);
}

function webapiSnow($string) {
    return preg_match('/^[0-9]{16,20}$/', $string);
}

$moderator = function (string &$message): false|string
{ //Function needs to be updated to read messages sent in #ic-emotes, #ooc, and #ahelp
    $cleaned = null;
    $badwords = ['beaner', 'chink', 'chink', 'coon', 'fag', 'gook', 'kike', 'nigg', 'nlgg', 'tranny']; //Move this to a .gitignore'd file?
    foreach ($badwords as $badword) {
        if (str_contains(strtolower($message), $badword)) {
            $filtered = substr($badword, 0, 1);
            for ($x=1;$x<strlen($badword)-2; $x++) $filtered .= '%';
            $filtered .= substr($badword, -1, 1);
            $cleaned = $message = str_replace($badword, $filtered, $message); //"Blacklisted word ($filtered), please appeal on our discord"
        }
    }
    return $cleaned ?? false;
};

$external_ip = file_get_contents('http://ipecho.net/plain');
$valzargaming_ip = gethostbyname('www.valzargaming.com');
$ps13_webhook_key = file_get_contents('webhook_key.txt');

$socket = new SocketServer(sprintf('%s:%s', '0.0.0.0', '55558'), $PS13->loop);
$webapi = new HttpServer($loop, function (ServerRequestInterface $request) use ($PS13, $moderator, $socket, $external_ip, $valzargaming_ip, $ps13_webhook_key)
{
    /*
    $path = explode('/', $request->getUri()->getPath());
    $sub = (isset($path[1]) ? (string) $path[1] : false);
    $id = (isset($path[2]) ? (string) $path[2] : false);
    $id2 = (isset($path[3]) ? (string) $path[3] : false);
    $ip = (isset($path[4]) ? (string) $path[4] : false);
    $idarray = array(); //get from post data (NYI)
    */
    
    $echo = 'API ';
    $sub = 'index.';
    $path = explode('/', $request->getUri()->getPath());
    $repository = $sub = (isset($path[1]) ? (string) strtolower($path[1]) : false); if ($repository) $echo .= "$repository";
    $method = $id = (isset($path[2]) ? (string) strtolower($path[2]) : false); if ($method) $echo .= "/$method";
    $id2 = $repository2 = (isset($path[3]) ? (string) strtolower($path[3]) : false); if ($id2) $echo .= "/$id2";
    $ip = $partial = $method2 = (isset($path[4]) ? (string) strtolower($path[4]) : false); if ($partial) $echo .= "/$partial";
    $id3 = (isset($path[5]) ? (string) strtolower($path[5]) : false); if ($id3) $echo .= "/$id3";
    $id4 = (isset($path[6]) ? (string) strtolower($path[6]) : false); if ($id4) $echo .= "/$id4";
    $PS13->logger->info($echo);
    
    if ($ip) $PS13->logger->info('API IP ' . $ip);
    $whitelist = [
        '127.0.0.1',
        $external_ip,
        $valzargaming_ip,
        '51.254.161.128',
        '69.244.83.231',
    ];
    $substr_whitelist = ['10.0.0.', '192.168.']; 
    $whitelisted = false;
    foreach ($substr_whitelist as $substr) if (substr($request->getServerParams()['REMOTE_ADDR'], 0, strlen($substr)) == $substr) $whitelisted = true;
    if (in_array($request->getServerParams()['REMOTE_ADDR'], $whitelist)) $whitelisted = true;
    
    if (! $whitelisted) $PS13->logger->info('API REMOTE_ADDR ' . $request->getServerParams()['REMOTE_ADDR']);
    
    $PS13->logger->info('[API METHOD] ' . $request->getMethod() . PHP_EOL);
    $PS13->logger->info('[PATH] ' . $request->getUri()->getPath() . PHP_EOL);
    
    if ($sub == 'ps13') {
        $params = $request->getQueryParams();
        var_dump($params);
        if (!isset($params['key']) || $params['key'] != $ps13_webhook_key) return new Response(401, ['Content-Type' => 'text/plain'], 'Unauthorized');
        if (!isset($params['method']) || !isset($params['data'])) return new Response(400, ['Content-Type' => 'text/plain'], 'Missing Paramters');
        $data = json_decode($params['data'], true);
        $time = '['.date('H:i:s', time()).']';
        $channel_id = '';
        $message = '';
        $ckey = '';
        $moderator_triggered = false;
        switch ($params['method']) {
            case 'ahelpmessage':
                $channel_id = $PS13->channel_ids['ahelp_channel'];
                if ($moderated = $moderator($data['$message'])) {
                    $moderator_triggered = true;
                    $data['$message'] = $moderated;
                }
                $message .= "**__{$time} AHELP__ {$data['ckey']}**: " . urldecode($data['message']);
                $ckey = $data['ckey'];
                break;
            case 'asaymessage':
                $channel_id = $PS13->channel_ids['asay_channel'];
                if ($moderated = $moderator($data['$message'])) {
                    $moderator_triggered = true;
                    $data['$message'] = $moderated;
                }
                $message .= "**__{$time} ASAY__ {$data['ckey']}**: " . urldecode($data['message']);
                $ckey = $data['ckey'];
                break;
            case 'oocmessage':
                $channel_id = $PS13->channel_ids['ooc_channel'];
                if ($moderated = $moderator($data['$message'])) {
                    $moderator_triggered = true;
                    $data['$message'] = $moderated;
                }
                $message .= "**__{$time} OOC__ {$data['ckey']}**: " . urldecode($data['message']);
                $ckey = $data['ckey'];
                break;
            case 'lobbymessage':
                $channel_id = $PS13->channel_ids['ooc_channel'];
                if ($moderated = $moderator($data['$message'])) {
                    $moderator_triggered = true;
                    $data['$message'] = $moderated;
                }
                $message .= "**__{$time} LOBBY__ {$data['ckey']}**: " . urldecode($data['message']);
                $ckey = $data['ckey'];
                break;
            case 'memessage':
                $channel_id = $PS13->channel_ids['ic_channel'];
                if ($moderated = $moderator($data['$message'])) {
                    $moderator_triggered = true;
                    $data['$message'] = $moderated;
                }
                $message .= "**__{$time} EMOTE__ {$data['ckey']}** " . urldecode($data['message']);
                $ckey = $data['ckey'];
                break;
            case 'garbage':
                $channel_id = $PS13->channel_ids['garbage_channel'];
                $message .= "**__{$time} GARBAGE__ {$data['ckey']}**: " . strip_tags($data['message']);
                $ckey = $data['ckey'];
                break;
            case 'token':
                echo "[DATA FOR {$params['method']}]: "; var_dump($params['data']); echo PHP_EOL;
                break;
            case 'respawn_notice':
                $channel_id = $PS13->channel_ids['ooc_channel'];
                if (isset($PS13->role_ids['respawn_notice'])) $message .= "<@&{$PS13->role_ids['respawn_notice']}>, ";
                $message .= urldecode($data['message']);
                break;
            case 'login':
                $channel_id = $PS13->channel_ids['login-logout_channel'];
                $message .= "{$data['ckey']} logged in.";
                $ckey = explode(' ', $data['ckey'])[0];
                break;
            case 'logout':
                $channel_id = $PS13->channel_ids['login-logout_channel'];
                $message .= "{$data['ckey']} logged out.";
                $ckey = strtolower(str_replace(['.', '_', ' '], '', explode('[DC]', $data['ckey'])[0]));
                break;
            case 'roundstatus':
                echo "[DATA FOR {$params['method']}]: "; var_dump($params['data']); echo PHP_EOL;
                break;
            case 'status_update':
                echo "[DATA FOR {$params['method']}]: "; var_dump($params['data']); echo PHP_EOL;
                break;
            case 'runtimemessage':
                $channel_id = $PS13->channel_ids['runtime_channel'];
                $message .= "**__{$time} RUNTIME__**: " . strip_tags($data['message']);
                break;
            default:
                return new Response(400, ['Content-Type' => 'text/plain'], 'Invalid Parameter');
        }
        if ($moderator_triggered && $ahelp = $PS13->discord->getChannel($PS13->channel_ids['ahelp_channel'])) { //alert admins, maybe add an in-game ban in a future update?
            $ahelp->sendMessage("<@&{$PS13->role_ids['longbeard']}>, `{$data['ckey']}` triggered the in-game message moderator: `$message`");
        }
        if ($channel_id && $message && $channel = $PS13->discord->getChannel($channel_id)) {
            if (! $ckey || ! $item = $PS13->verified->get('ss13', strtolower(str_replace(['.', '_', ' '], '', explode('/', $ckey)[0])))) $channel->sendMessage($message);
            elseif ($user = $PS13->discord->users->get('id', $item['discord'])) {
                $embed = new Embed($PS13->discord);
                $embed->setAuthor("{$user->displayname} ({$user->id})", $user->avatar);
                $embed->setDescription($message);
                $channel->sendEmbed($embed);
            } elseif($item) {
                $PS13->discord->users->fetch('id', $item['discord']);
                $channel->sendMessage($message);
            } else {
                $channel->sendMessage($message);
            }
        }
        return new Response(200, ['Content-Type' => 'text/html'], 'Done');
    }

    switch ($sub) {
        case (str_starts_with($sub, 'index.')):
            $return = '<meta http-equiv = \"refresh\" content = \"0; url = https://www.valzargaming.com/?login\" />'; //Redirect to the website to log in
            return new Response(200, ['Content-Type' => 'text/html'], $return);
            break;
        case 'github':
            $return = '<meta http-equiv = \"refresh\" content = \"0; url = https://github.com/VZGCoders/PS13-Bot\" />'; //Redirect to the website to log in
            return new Response(200, ['Content-Type' => 'text/html'], $return);
            break;
        case 'favicon.ico':
            if (! $whitelisted) {
                $PS13->logger->info('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            $favicon = file_get_contents('favicon.ico');
            return new Response(200, ['Content-Type' => 'image/x-icon'], $favicon);
        
        case 'nohup.out':
            if (! $whitelisted) {
                $PS13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            if ($return = file_get_contents('nohup.out')) return new Response(200, ['Content-Type' => 'text/plain'], $return);
            else return new Response(501, ['Content-Type' => 'text/plain'], "Unable to access `nohup.out`");
            break;
        
        case 'botlog':
            if (! $whitelisted) {
                $PS13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            if ($return = file_get_contents('botlog.txt')) return new Response(200, ['Content-Type' => 'text/html'], '<meta name="color-scheme" content="light dark"> <div class="checkpoint">' . str_replace('[' . date("Y"), '</div><div> [' . date("Y"), str_replace([PHP_EOL, '[] []', ' [] '], '</div><div>', $return)) . "</div><script>var mainScrollArea=document.getElementsByClassName('checkpoint')[0];var scrollTimeout;window.onload=function(){if(window.location.href==localStorage.getItem('lastUrl')){mainScrollArea.scrollTop=localStorage.getItem('scrollTop');}else{localStorage.setItem('lastUrl',window.location.href);localStorage.setItem('scrollTop',0);}};mainScrollArea.addEventListener('scroll',function(){clearTimeout(scrollTimeout);scrollTimeout=setTimeout(function(){localStorage.setItem('scrollTop',mainScrollArea.scrollTop);},100);});setTimeout(locationreload,10000);function locationreload(){location.reload();}</script>");
            else return new Response(501, ['Content-Type' => 'text/plain'], "Unable to access `botlog.txt`");
            break;
            
        case 'botlog2':
            if (! $whitelisted) {
                $PS13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            if ($return = file_get_contents('botlog2.txt')) return new Response(200, ['Content-Type' => 'text/html'], '<meta name="color-scheme" content="light dark"> <div class="checkpoint">' . str_replace('[' . date("Y"), '</div><div> [' . date("Y"), str_replace([PHP_EOL, '[] []', ' [] '], '</div><div>', $return)) . "</div><script>var mainScrollArea=document.getElementsByClassName('checkpoint')[0];var scrollTimeout;window.onload=function(){if(window.location.href==localStorage.getItem('lastUrl')){mainScrollArea.scrollTop=localStorage.getItem('scrollTop');}else{localStorage.setItem('lastUrl',window.location.href);localStorage.setItem('scrollTop',0);}};mainScrollArea.addEventListener('scroll',function(){clearTimeout(scrollTimeout);scrollTimeout=setTimeout(function(){localStorage.setItem('scrollTop',mainScrollArea.scrollTop);},100);});setTimeout(locationreload,10000);function locationreload(){location.reload();}</script>");
            else return new Response(501, ['Content-Type' => 'text/plain'], "Unable to access `botlog2.txt`");
            break;
        
        case 'channel':
            if (! $id || !webapiSnow($id) || !$return = $PS13->discord->getChannel($id)) return webapiFail('channel_id', $id);
            break;

        case 'guild':
            if (! $id || !webapiSnow($id) || !$return = $PS13->discord->guilds->get('id', $id)) return webapiFail('guild_id', $id);
            break;

        case 'bans':
            if (! $id || !webapiSnow($id) || !$return = $PS13->discord->guilds->get('id', $id)->bans) return webapiFail('guild_id', $id);
            break;

        case 'channels':
            if (! $id || !webapiSnow($id) || !$return = $PS13->discord->guilds->get('id', $id)->channels) return webapiFail('guild_id', $id);
            break;

        case 'members':
            if (! $id || !webapiSnow($id) || !$return = $PS13->discord->guilds->get('id', $id)->members) return webapiFail('guild_id', $id);
            break;

        case 'emojis':
            if (! $id || !webapiSnow($id) || !$return = $PS13->discord->guilds->get('id', $id)->emojis) return webapiFail('guild_id', $id);
            break;

        case 'invites':
            if (! $id || !webapiSnow($id) || !$return = $PS13->discord->guilds->get('id', $id)->invites) return webapiFail('guild_id', $id);
            break;

        case 'roles':
            if (! $id || !webapiSnow($id) || !$return = $PS13->discord->guilds->get('id', $id)->roles) return webapiFail('guild_id', $id);
            break;

        case 'guildMember':
            if (! $id || !webapiSnow($id) || !$guild = $PS13->discord->guilds->get('id', $id)) return webapiFail('guild_id', $id);
            if (! $id2 || !webapiSnow($id2) || !$return = $guild->members->get('id', $id2)) return webapiFail('user_id', $id2);
            break;

        case 'user':
            if (! $id || !webapiSnow($id) || !$return = $PS13->discord->users->get('id', $id)) return webapiFail('user_id', $id);
            break;

        case 'userName':
            if (! $id || !$return = $PS13->discord->users->get('name', $id)) return webapiFail('user_name', $id);
            break;
        
        case 'reset':
            if (! $whitelisted) {
                $PS13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            execInBackground('git reset --hard origin/main');
            $return = 'fixing git';
            break;
        
        case 'pull':
            if (! $whitelisted) {
                $PS13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            execInBackground('git pull');
            $PS13->logger->info('[GIT PULL]');
            if ($channel = $PS13->discord->getChannel('712685552155230278')) $channel->sendMessage('Updating code from GitHub...');
            $return = 'updating code';
            break;
        
        case 'update':
            if (! $whitelisted) {
                $PS13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            execInBackground('composer update');
            $PS13->logger->info('[COMPOSER UPDATE]');
            if ($channel = $PS13->discord->getChannel('712685552155230278')) $channel->sendMessage('Updating dependencies...');
            $return = 'updating dependencies';
            break;
        
        case 'restart':
            if (! $whitelisted) {
                $PS13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            $PS13->logger->info('[RESTART]');
            if ($channel = $PS13->discord->getChannel('712685552155230278')) $channel->sendMessage('Restarting...');
            $return = 'restarting';
            $socket->close();
            $PS13->discord->getLoop()->addTimer(5, function () use ($PS13) {
                \restart();
                $PS13->discord->close();
                die();
            });
            break;

        case 'lookup':
            if (! $whitelisted) {
                $PS13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            if (! $id || !webapiSnow($id) || !$return = $PS13->discord->users->get('id', $id)) return webapiFail('user_id', $id);
            break;

        case 'owner':
            if (! $whitelisted) {
                $PS13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            if (! $id || !webapiSnow($id)) return webapiFail('user_id', $id); $return = false;
            if ($user = $PS13->discord->users->get('id', $id)) { //Search all guilds the bot is in and check if the user id exists as a guild owner
                foreach ($PS13->discord->guilds as $guild) {
                    if ($id == $guild->owner_id) {
                        $return = true;
                        break 1;
                    }
                }
            }
            break;

        case 'avatar':
            if (! $id || !webapiSnow($id)) return webapiFail('user_id', $id);
            if (! $user = $PS13->discord->users->get('id', $id)) $return = 'https://cdn.discordapp.com/embed/avatars/'.rand(0,4).'.png';
            else $return = $user->avatar;
            //if (! $return) return new Response(($id ? 404 : 400), ['Content-Type' => 'text/plain'], (''));
            break;

        case 'avatars': //This needs to be optimized to not use async code
            /*
            $idarray = $data ?? array(); // $data contains POST data
            $results = [];
            $promise = $PS13->discord->users->fetch($idarray[0])->then(function ($user) use (&$results) {
              $results[$user->id] = $user->avatar;
            });
            
            for ($i = 1; $i < count($idarray); $i++) {
                $discord = $PS13->discord;
                $promise->then(function () use (&$results, $idarray, $i, $discord) {
                return $PS13->discord->users->fetch($idarray[$i])->then(function ($user) use (&$results) {
                    $results[$user->id] = $user->avatar;
                });
              });
            }

            $promise->done(function () use ($results) {
              return new Response (200, ['Content-Type' => 'application/json'], json_encode($results));
            }, function () use ($results) {
              // return with error ?
              return new Response(200, ['Content-Type' => 'application/json'], json_encode($results));
            });
            */
            $return = '';
            break;
        
        case 'ps13':
            switch ($id) {
                case 'bans':
                    if (! $whitelisted) {
                        $PS13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                        return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
                    }
                    $bans = $PS13->files['bans'];
                    if ($return = file_get_contents($bans)) return new Response(200, ['Content-Type' => 'text/plain'], $return);
                    else return new Response(501, ['Content-Type' => 'text/plain'], "Unable to access `$bans`");
                    break;
                default:
                    return new Response(501, ['Content-Type' => 'text/plain'], 'Not implemented');
            }
            break;
        case 'tdm':
            switch ($id) {
                case 'bans':
                    if (! $whitelisted) {
                        $PS13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                        return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
                    }
                    $tdm_bans = $PS13->files['tdm_bans'];
                    if ($return = file_get_contents($tdm_bans)) return new Response(200, ['Content-Type' => 'text/plain'], $return);
                    else return new Response(501, ['Content-Type' => 'text/plain'], "Unable to access `$tdm_bans`");
                    break;
                default:
                    return new Response(501, ['Content-Type' => 'text/plain'], 'Not implemented');
            }
            break;
        
        case 'discord2ckey':
            if (! $id || !webapiSnow($id) || !is_numeric($id)) return webapiFail('user_id', $id);
            $discord2ckey = $PS13->functions['misc']['discord2ckey'];
            $return = $discord2ckey($PS13, $id);
            return new Response(200, ['Content-Type' => 'text/plain'], $return);
            break;
            
        case 'verified':
            return new Response(200, ['Content-Type' => 'text/plain'], json_encode($PS13->verified->toArray()));
            break;
            
        default:
            return new Response(501, ['Content-Type' => 'text/plain'], 'Not implemented');
    }
    return new Response(200, ['Content-Type' => 'text/json'], json_encode($return));
});
$webapi->listen($socket);
$webapi->on('error', function ($e) use ($PS13) {
    $PS13->logger->error('API ' . $e->getMessage());
});