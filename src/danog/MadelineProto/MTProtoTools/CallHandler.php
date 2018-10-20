<?php

/*
Copyright 2016-2018 Daniil Gentili
(https://daniil.it)
This file is part of MadelineProto.
MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU Affero General Public License for more details.
You should have received a copy of the GNU General Public License along with MadelineProto.
If not, see <http://www.gnu.org/licenses/>.
 */

namespace danog\MadelineProto\MTProtoTools;

/**
 * Manages method and object calls.
 */
trait CallHandler
{
    public function select()
    {
        $result = [];
        try {
            /*
            if ($this->is_http($this->datacenter->curdc) || $this->altervista) {
                $this->logger->logger("Initial HTTP short poll");
                $waiting = $this->datacenter->select(0.1);
                $result = $this->handle_select($waiting, $result);
            }*/
            $tries = 10; // TODO add setting
            $this->logger->logger("Long poll");
            $t = microtime(true);
            $waiting = $this->datacenter->select();
            $t = microtime(true) - $t;
            $this->logger->logger("Long poll took $t");

            $result = $this->handle_select($waiting, $result);

            do {
                $this->logger->logger("Short poll");
                $waiting = $this->datacenter->select($this->is_http($this->datacenter->curdc) || $this->altervista ? $this->settings['connection_settings']['all']['timeout']/10 : true);
                $result = $this->handle_select($waiting, $result);
            } while ($tries-- && $waiting);
        } catch (\danog\MadelineProto\NothingInTheSocketException $e) {
            $this->logger->logger('Nothing in the socket while selecting', \danog\MadelineProto\Logger::ULTRA_VERBOSE);
            foreach ($this->datacenter->sockets as $dc => $socket) {
                $this->close_and_reopen($dc);
                $this->send_messages($dc);
            }
        }
        return $result;
    }
    public $did = [];

    public function handle_select($waiting, $result) {
        foreach ($waiting as $dc) {
            $error = $this->recv_message($dc);
            if ($error !== true) {
                $this->close_and_reopen($dc);
                if ($error === -404) {
                    if ($this->datacenter->sockets[$dc]->temp_auth_key !== null) {
                        $this->logger->logger('WARNING: Resetting auth key...', \danog\MadelineProto\Logger::WARNING);
                        $this->datacenter->sockets[$dc]->temp_auth_key = null;
                        $this->init_authorization();

                        return $result;
                    }
                }

                throw new \danog\MadelineProto\RPCErrorException($error, $error);
            }
            $result[$dc] = $this->handle_messages($dc) && (isset($result[$dc]) ? $result[$dc] : true);
            if (($this->is_http($dc) || $this->altervista) && $this->datacenter->sockets[$dc]->new_outgoing) {
                $this->send_messages($dc);
            }
        }
        return $result;

    }
    public function iorun($updates)
    {
        do {

            if ($updates && time() - $this->last_getdifference > $this->settings['updates']['getdifference_interval']) {
                $this->get_updates_difference();
                return;
            }

            if ($canunset = !$this->updates_state['sync_loading']) {
                $this->updates_state['sync_loading'] = true;
            }
            if ($canunsetpostponeupdates = !$this->postpone_updates) {
                $this->postpone_updates = true;
            }
            if ($canunsetpostponepwrchat = !$this->postpone_pwrchat) {
                $this->postpone_pwrchat = true;
            }

            if (($this->is_http($this->datacenter->curdc) || $this->altervista) && $updates) {
                $this->send_messages($this->datacenter->curdc);
            }

            $this->logger->logger("Polling for " . ($updates ? 'updates' : 'replies') . ": selecting", \danog\MadelineProto\Logger::ULTRA_VERBOSE);
            $t = microtime(true);
            $only_updates = $this->select();
            $t = microtime(true) - $t;
            $this->logger->logger("Polling for " . ($updates ? 'updates' : 'replies') . ": selecting took " . $t, \danog\MadelineProto\Logger::ULTRA_VERBOSE);
            $response_result = $this->has_pending_calls();

            $repeat = 0;
            foreach ($this->datacenter->sockets as $id => $datacenter) {
                if ($updates) {
                    if (isset($only_updates[$id])) {
                        if ($only_updates[$id]) {
                            $this->logger->logger("Polling for updates: got only updates for DC $id", \danog\MadelineProto\Logger::VERBOSE);
                        } else {
                            $this->logger->logger("Polling for updates: got also RPC replies for DC $id", \danog\MadelineProto\Logger::NOTICE);
                        }
                        if ($response_result[$id]) {
                            $this->logger->logger("Polling for updates: still pending requests, resending for DC $id", \danog\MadelineProto\Logger::WARNING);
                            $this->send_messages($id);
                        }
                    } else {
                        if ($response_result[$id] || $id === $this->datacenter->curdc) {
                            $this->logger->logger("Polling for updates: got nothing for DC $id", \danog\MadelineProto\Logger::ERROR);

                            if ($this->is_http($id) || $this->altervista) {
                                $this->logger->logger("Polling for updates: closing and reopening DC $id since we're on HTTP, and we polled");
                                $this->close_and_reopen($id);
                                $datacenter->last_http_wait = 0;
                                $repeat |= 1;
                                $this->logger->logger("Polling for updates: and now repolling for DC $id");
                                $this->send_messages($id);
                            }
                        }
                    }
                } else {
                    if (isset($only_updates[$id])) {
                        if ($only_updates[$id]) {
                            $this->logger->logger("Polling for replies: got only updates for DC $id", \danog\MadelineProto\Logger::WARNING);

                            if ($response_result[$id]) {
                                $this->logger->logger("Polling for replies: still pending requests, repolling for DC $id", \danog\MadelineProto\Logger::WARNING);
                                $this->send_messages($id);
                                $repeat |= 1;
                            } else {
                                $this->logger->logger("Polling for replies: got all RPC replies for DC $id", \danog\MadelineProto\Logger::ULTRA_VERBOSE);
                            }
                        } else {
                            if ($response_result[$id]) {
                                $this->logger->logger("Polling for replies: still pending requests, repolling for DC $id", \danog\MadelineProto\Logger::WARNING);
                                $this->send_messages($id);
                            } else {
                                $this->logger->logger("Polling for replies: got all RPC replies for DC $id", \danog\MadelineProto\Logger::NOTICE);
                            }
                        }
                    } else {
                        if ($response_result[$id]) {
                            $this->logger->logger("Polling for replies: got nothing for DC $id", \danog\MadelineProto\Logger::ERROR);
                            $this->logger->logger("Polling for replies: closing and reopening DC $id", \danog\MadelineProto\Logger::ERROR);
                            $this->close_and_reopen($id);
                            $datacenter->last_http_wait = 0;
                            $repeat |= 1;
                            $this->logger->logger("Polling for replies: resending for DC $id", \danog\MadelineProto\Logger::WARNING);
                            $this->send_messages($id);
                        } else {
                            $this->logger->logger("Polling for replies: got all RPC replies for DC $id", \danog\MadelineProto\Logger::ULTRA_VERBOSE);
                        }
                    }
                }
            }
            $this->logger->logger("Running guzzle promise queue");
            \GuzzleHttp\Promise\queue()->run();

            if ($repeat) {
                $this->logger->logger("Repeat iowait");
            }
        } while ($repeat);

        if ($canunset) {
            $this->updates_state['sync_loading'] = false;
        }
        if ($canunsetpostponepwrchat) {
            $this->postpone_pwrchat = false;
            $this->handle_pending_pwrchat();
        }
        if ($canunsetpostponeupdates) {
            $this->postpone_updates = false;
            $this->handle_pending_updates();
        }
    }

    public function has_pending_calls()
    {
        $result = [];
        foreach ($this->datacenter->sockets as $id => $socket) {
            $result[$id] = $this->has_pending_calls_dc($id);
        }
        return $result;
    }

    public function has_pending_calls_dc($datacenter)
    {
        //$result = 0;
        $dc_config_number = isset($this->settings['connection_settings'][$datacenter]) ? $datacenter : 'all';
        foreach ($this->datacenter->sockets[$datacenter]->new_outgoing as $message_id) {
            if (isset($this->datacenter->sockets[$datacenter]->outgoing_messages[$message_id]['sent']) && ($this->datacenter->sockets[$datacenter]->outgoing_messages[$message_id]['sent'] + $this->settings['connection_settings'][$dc_config_number]['timeout'] < time()) && ($this->datacenter->sockets[$datacenter]->temp_auth_key === null) === isset($this->datacenter->sockets[$datacenter]->outgoing_messages[$message_id]['unencrypted']) && $this->datacenter->sockets[$datacenter]->outgoing_messages[$message_id]['_'] !== 'msgs_state_req') {
                return true;
                //$result |= 1;
            }
        }
        return false; //(bool) $result;
    }
    public function check_pending_calls()
    {
        foreach ($this->datacenter->sockets as $datacenter => $socket) {
            $this->check_pending_calls_dc($datacenter);
        }
    }

    public function check_pending_calls_dc($datacenter)
    {
        if (!empty($this->datacenter->sockets[$datacenter]->new_outgoing)) {
            if ($this->has_pending_calls_dc($datacenter)) {
                if ($this->datacenter->sockets[$datacenter]->temp_auth_key !== null) {
                    $thiz = $this;
                    $message_ids = array_values($this->datacenter->sockets[$datacenter]->new_outgoing);
                    $promise = new \danog\MadelineProto\ImmediatePromise();
                    $promise->then(
                        function ($result) use ($datacenter, $thiz, $message_ids) {
                            $reply = [];
                            foreach (str_split($result['info']) as $key => $chr) {
                                $message_id = $message_ids[$key];
                                if (!isset($this->datacenter->sockets[$datacenter]->outgoing_messages[$message_id])) {
                                    $thiz->logger->logger('Already got response for and forgot about message ID ' . $this->unpack_signed_long($message_id));
                                    continue;
                                }
                                if (!isset($this->datacenter->sockets[$datacenter]->new_outgoing[$message_id])) {
                                    $thiz->logger->logger('Already got response for ' . $this->datacenter->sockets[$datacenter]->outgoing_messages[$message_id]['_'] . ' with message ID ' . $this->unpack_signed_long($message_id));
                                    continue;
                                }
                                $chr = ord($chr);
                                switch ($chr & 7) {
                                    case 0:
                                        $thiz->logger->logger('Wrong message status 0 for ' . $this->datacenter->sockets[$datacenter]->outgoing_messages[$message_id]['_'], \danog\MadelineProto\Logger::FATAL_ERROR);
                                        break;
                                    case 1:
                                    case 2:
                                    case 3:
                                        $thiz->logger->logger('Message ' . $this->datacenter->sockets[$datacenter]->outgoing_messages[$message_id]['_'] . " with message ID " . $this->unpack_signed_long($message_id) . " not received by server, resending...", \danog\MadelineProto\Logger::ERROR);
                                        $this->method_recall($message_id, $datacenter, false, true);
                                        break;
                                    case 4:
                                        if ($chr & 32) {
                                            $thiz->logger->logger('Message ' . $this->datacenter->sockets[$datacenter]->outgoing_messages[$message_id]['_'] . " with message ID " . $this->unpack_signed_long($message_id) . " received by server and is being processed, waiting...", \danog\MadelineProto\Logger::ERROR);
                                        } else if ($chr & 64) {
                                            $thiz->logger->logger('Message ' . $this->datacenter->sockets[$datacenter]->outgoing_messages[$message_id]['_'] . " with message ID " . $this->unpack_signed_long($message_id) . " received by server and was already processed, requesting reply...", \danog\MadelineProto\Logger::ERROR);
                                            $reply[] = $message_id;
                                        } else if ($chr & 128) {
                                            $thiz->logger->logger('Message ' . $this->datacenter->sockets[$datacenter]->outgoing_messages[$message_id]['_'] . " with message ID " . $this->unpack_signed_long($message_id) . " received by server and was already sent, requesting reply...", \danog\MadelineProto\Logger::ERROR);
                                            $reply[] = $message_id;
                                        } else {
                                            $thiz->logger->logger('Message ' . $this->datacenter->sockets[$datacenter]->outgoing_messages[$message_id]['_'] . " with message ID " . $this->unpack_signed_long($message_id) . " received by server, requesting reply...", \danog\MadelineProto\Logger::ERROR);
                                            $reply[] = $message_id;
                                        }
                                }
                            }
                            if ($reply) {
                                $this->object_call('msg_resend_ans_req', ['msg_ids' => $reply], ['datacenter' => $datacenter, 'postpone' => true]);
                            }
                            $this->send_messages($datacenter);
                        },
                        function ($error) use ($datacenter, $thiz) {
                            throw $error;
                        }
                    );
                    $this->logger->logger("Still missing something on DC $datacenter, sending state request", \danog\MadelineProto\Logger::ERROR);
                    $this->object_call('msgs_state_req', ['msg_ids' => $message_ids], ['datacenter' => $datacenter, 'promise' => $promise]);
                } else {
                    $dc_config_number = isset($this->settings['connection_settings'][$datacenter]) ? $datacenter : 'all';
                    foreach ($this->datacenter->sockets[$datacenter]->new_outgoing as $message_id) {
                        if (isset($this->datacenter->sockets[$datacenter]->outgoing_messages[$message_id]['sent']) && $this->datacenter->sockets[$datacenter]->outgoing_messages[$message_id]['sent'] + $this->settings['connection_settings'][$dc_config_number]['timeout'] < time() && isset($this->datacenter->sockets[$datacenter]->outgoing_messages[$message_id]['unencrypted']) && $this->datacenter->sockets[$datacenter]->outgoing_messages[$message_id]['unencrypted']) {
                            $this->logger->logger("Still missing " . $this->datacenter->sockets[$datacenter]->outgoing_messages[$message_id]['_'] . " with message id " . $this->unpack_signed_long($message_id) . " on DC $datacenter, resending", \danog\MadelineProto\Logger::ERROR);
                            $this->method_recall($message_id, $datacenter, false, true);
                        }
                    }
                }

            }
        }
    }

    public function method_recall($message_id, $new_datacenter, $old_datacenter = false, $postpone = false)
    {
        if ($old_datacenter === false) {
            $old_datacenter = $new_datacenter;
        }

        if (isset($this->datacenter->sockets[$old_datacenter]->outgoing_messages[$message_id]['container'])) {
            $message_ids = $this->datacenter->sockets[$old_datacenter]->outgoing_messages[$message_id]['container'];
        } else {
            $message_ids = [$message_id];
        }

        foreach ($message_ids as $message_id) {
            if (isset($this->datacenter->sockets[$old_datacenter]->outgoing_messages[$message_id]['body'])) {
                $this->append_message($this->datacenter->sockets[$old_datacenter]->outgoing_messages[$message_id], $new_datacenter);
                $this->ack_outgoing_message_id($message_id, $old_datacenter);
                $this->got_response_for_outgoing_message_id($message_id, $old_datacenter);
            }
        }
        if ($this->datacenter->sockets[$new_datacenter]->temp_auth_key !== null && !$postpone) {
            $this->send_messages($new_datacenter);
        }
    }

    public function method_call($method, $args = [], $aargs = ['msg_id' => null, 'heavy' => false])
    {
        $promise = $this->method_call_async($method, $args, $aargs);
        $promise->then(
            function ($result) use (&$out) {
                $out = $result;
            },
            function ($result) use (&$error) {
                $error = $result;
            }
        );
        $promise->wait();
        if (isset($error)) {
            throw $error;
        }
        return $out;
    }

    public function method_call_async($method, $args = [], $aargs = ['msg_id' => null, 'heavy' => false])
    {
        if (isset($args['id']['_']) && isset($args['id']['dc_id']) && $args['id']['_'] === 'inputBotInlineMessageID') {
            $aargs['datacenter'] = $args['id']['dc_id'];
        }
        if ($this->wrapper instanceof \danog\MadelineProto\API && isset($this->wrapper->session) && !is_null($this->wrapper->session) && time() - $this->wrapper->serialized > $this->settings['serialization']['serialization_interval']) {
            $this->logger->logger("Didn't serialize in a while, doing that now...");
            $this->wrapper->serialize($this->wrapper->session);
        }
        if (isset($aargs['file']) && $aargs['file'] && isset($this->datacenter->sockets[$aargs['datacenter'].'_media'])) {
            \danog\MadelineProto\Logger::log('Using media DC');
            $aargs['datacenter'] .= '_media';
        }
        if (in_array($method, ['messages.setEncryptedTyping', 'messages.readEncryptedHistory', 'messages.sendEncrypted', 'messages.sendEncryptedFile', 'messages.sendEncryptedService', 'messages.receivedQueue'])) {
            $aargs['queue'] = 'secret';
        }

        if (isset($args['message']) && is_string($args['message']) && $this->mb_strlen($args['message']) > $this->config['message_length_max']) {
            // TODO multiple promise resolution
            $arg_chunks = $this->split_to_chunks($args);
            /*$promise = new \GuzzleHttp\Promise\Promise();
            foreach ($args_chunks as $args) {
            $this->method_call_async($method, $args, $aargs)->then(function );
            }
            if ($promises)
             */
            return $promise;
        }
        $args = $this->botAPI_to_MTProto($args);
        if (isset($args['ping_id']) && is_int($args['ping_id'])) {
            $args['ping_id'] = $this->pack_signed_long($args['ping_id']);
        }

        $zis = $this;
        $promise = new \GuzzleHttp\Promise\Promise(function () use (&$zis, $method, $aargs, &$promise) {
            do {
                $zis->logger->logger("Waiting reply for " . $method . " from DC " . $aargs['datacenter'] . ", current status " . $promise->getState());
                $zis->iorun(false);
            } while ($promise->getState() === 'pending');
            $zis->logger->logger("Got reply for " . $method . " from DC " . $aargs['datacenter'] . ", current status " . $promise->getState());
        });
        $message = ['_' => $method, 'type' => $this->methods->find_by_method($method)['type'], 'content_related' => $this->content_related($method), 'promise' => $promise, 'method' => true];

        if (isset($aargs['serialized'])) {
            $message['body'] = $aargs['serialized'];
        } else {
            $message['body'] = $this->serialize_method($method, $args);
        }
        if (isset($aargs['msg_id'])) {
            $message['msg_id'] = $aargs['msg_id'];
        }
        if (isset($aargs['file'])) {
            $message['file'] = $aargs['file'];
        }
        if (isset($aargs['botAPI'])) {
            $message['botAPI'] = $aargs['botAPI'];
        }
        if (($method === 'users.getUsers' && $args === ['id' => [['_' => 'inputUserSelf']]]) || $method === 'auth.exportAuthorization' || $method === 'updates.getDifference') {
            $message['user_related'] = true;
        }

        $this->append_message($message, $aargs['datacenter']);
        if ($this->datacenter->sockets[$aargs['datacenter']]->temp_auth_key !== null && !isset($aargs['postpone'])) {
            $this->send_messages($aargs['datacenter']);
        }
        return $promise;
    }

    public function object_call($object, $args = [], $aargs = ['msg_id' => null, 'heavy' => false])
    {
        $message = ['_' => $object, 'body' => $this->serialize_object(['type' => $object], $args, $object), 'content_related' => $this->content_related($object)];
        if (isset($aargs['promise'])) {
            $message['promise'] = $aargs['promise'];
        }
        $this->append_message($message, $aargs['datacenter']);
    }

    /*
    $message = [
    // only in outgoing messages
    'body' => 'serialized body', (optional if container)
    'content_related' => bool,
    '_' => 'predicate',
    'promise' => promise object (optional),
    'file' => bool (optional),
    'type' => 'type' (optional),
    'queue' => queue ID (optional),
    'container' => [message ids] (optional),

    // only in incoming messages
    'content' => deserialized body,
    'seq_no' => number (optional),
    'from_container' => bool (optional),

    // can be present in both
    'response' => message id (optional),
    'msg_id' => message id (optional),
    'sent' => timestamp,
    'tries' => number
    ];
     */
    public function append_message($message, $datacenter)
    {
        if ($this->datacenter->sockets[$datacenter]->temp_auth_key !== null) {
            $this->datacenter->sockets[$datacenter]->pending_outgoing[$this->datacenter->sockets[$datacenter]->pending_outgoing_key++] = $message;
        } else {
            $this->send_unencrypted_message($message, $datacenter);
        }
    }
}
