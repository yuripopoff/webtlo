<?php

include_once dirname(__FILE__) . '/../../common.php';
include_once dirname(__FILE__) . '/../../api.php';
include_once dirname(__FILE__) . '/../../clients.php';
include_once dirname(__FILE__) . '/../classes/reports.php';

$starttime = microtime( true );

Log::append( "Начато обновление сведений о раздачах..." );

// получение настроек
if ( ! isset( $cfg ) ) {
    $cfg = get_settings();
}

// проверка настроек
if ( empty( $cfg['subsections'] ) ) {
    throw new Exception( "Error: Не выбраны хранимые подразделы" );
}

Log::append( "Получение данных от торрент-клиентов..." );
Log::append( "Количество торрент-клиентов: " . count( $cfg['clients'] ) );

// array( [hash] => ( 'status' => status, 'client' => comment ) )
// status: 0 - загружается, 1 - раздаётся, -1 - на паузе или стопе
$clients_data = array();

if ( ! empty( $cfg['clients'] ) ) {
    foreach ( $cfg['clients'] as $client_id => $client_info ) {
        $data = array();
        $client = new $client_info['cl'] (
            $client_info['ht'], $client_info['pt'], $client_info['lg'],
            $client_info['pw'], $client_info['cm']
        );
        if ( $client->is_online() ) {
            $data = $client->getTorrents( $client_id );
            $clients_data += $data;
            unset( $data );
        }
        Log::append( $client_info['cm'] . ' (' . $client_info['cl'] . ') - получено раздач: ' . count( $data ) );
    }
}

// создаём временную таблицу
Db::query_database( "CREATE TEMP TABLE Topics1 AS SELECT * FROM Topics WHERE 0 = 1" );

// получаем дату предыдущего обновления
$update_time = Db::query_database( "SELECT ud FROM Other", array(), true, PDO::FETCH_COLUMN );
$previous_update_time = new DateTime();
$previous_update_time->setTimestamp( $update_time[0] )->setTime( 0, 0, 0 );

// подключаемся к api
$api = new Api ( $cfg['api_url'], $cfg['api_key'] );

// обновление дерева подразделов
$api->get_cat_forum_tree();

foreach ( $cfg['subsections'] as $forum_id => $subsection ) {

    // получаем данные о раздачах
    $topics_data = $api->get_forum_topics_data( $forum_id );
    if ( empty( $topics_data['result'] ) ) {
        throw new Exception( "Error: Не получены данные о подразделе № " . $forum_id );
    }

    // количество и вес раздач
    $topics_count = count( $topics_data['result'] );
    $update_time = $topics_data['update_time'];
    $topics_size = $topics_datap['total_size_bytes'];

    // получаем данные о раздачах за предыдущее обновление
    if ( $cfg['avg_seeders'] ) {
        $topics_data_previous = array();
        $topics_ids = array_keys( $topics_data['result'] );
        $topics_ids = array_chunk( $topics_ids, 500 );
        foreach ( $topics_ids as $topics_ids ) {
            $in = str_repeat( '?,', count( $topics_ids ) - 1 ) . '?';
            $topics_data_previous += Db::query_database(
                "SELECT id,se,rg,qt,ds FROM Topics WHERE id IN ($in)",
                $topics_ids, true, PDO::FETCH_ASSOC|PDO::FETCH_UNIQUE
            );
        }
        unset( $topics_ids );
    }

    // "topic_id": [
    //     "tor_status",    0
    //     "seeders",       1
    //     "reg_time",      2
    //     "tor_size_bytes" 3
    // ]

    // разбираем раздачи
    foreach ( $topics_data['result'] as $topic_id => $topic_data ) {
        if ( empty( $topic_data ) ) {
            continue;
        }
        if ( count( $topic_data ) < 4 ) {
            throw new Exception( "Error: Недостаточно элементов в ответе" );
        }

        if ( isset( $topics_data_previous[ $topic_id ] ) ) {
            $previous_data = $topics_data_previous[ $topic_id ];
        }

        if ( empty( $previous_data ) || $previous_data['rg'] != $topic_data[2] ) {
            // получать для раздач info_hash и topic_title
        }

        if ( empty( $previous_data ) ) {
            if ( $previous_data['rg'] == $topic_data[2] ) {
                // переносим старые значения
                $days = $previous_data['ds'];
                // по прошествии дня
                if ( ! empty( $last ) && $current->diff( $last )->format('%d' ) > 0 ) {
                    $days++;
                } else {
                    $sum_updates += $previous_data['qt'];
                    $sum_seeders += $previous_data['se'];
                }
            } else {
                // получать для раздач info_hash и topic_title
            }
        } elseif ( $previous_data['rg'] != $topic_data[2] ) {
            // удалять перерегистрированные раздачи
            // $topics_delele[] = $topic_id;
        }

        $tmp['topics'][ $topic_id ]['id'] = $topic_id;
        $tmp['topics'][ $topic_id ]['ss'] = $forum_id;
        $tmp['topics'][ $topic_id ]['na'] = $topic_data['topic_title'];
        $tmp['topics'][ $topic_id ]['hs'] = $topic_data['info_hash'];
        //
        $days = 0;
        $sum_updates = 1;
        $sum_seeders = $topic_data[1];
        if ( isset( $topics_data_previous[ $topic_id ] ) ) {
            $previous_data = $topics_data_previous[ $topic_id ];
            if ( empty( $previous_data['rg'] ) || $previous_data['rg'] == $topic_data[2] ) {
                // переносим старые значения
                $days = $previous_data['ds'];
                // по прошествии дня
                if ( ! empty( $last ) && $current->diff( $last )->format('%d' ) > 0 ) {
                    $days++;
                } else {
                    $sum_updates += $previous_data['qt'];
                    $sum_seeders += $previous_data['se'];
                }
            } else {
                $topics_delele[] = $topic_id;
            }
        }
        $tmp['topics'][ $topic_id ]['se'] = $sum_seeders;
        $tmp['topics'][ $topic_id ]['si'] = $topic_data[3];
        $tmp['topics'][ $topic_id ]['st'] = $topic_data[0];
        $tmp['topics'][ $topic_id ]['rg'] = $topic_data[2];
        // "0" - не храню, "1" - храню (раздаю), "-1" - храню (качаю), "-2" - из других подразделов
        $tmp['topics'][ $topic_id ]['dl'] = isset( $tc_topics[ $topic_data['info_hash'] ] )
            ? $stored
                ? empty( $tc_topics[ $topic_data['info_hash'] ]['status'] )
                    ? -1
                    : 1
                : -2
            : 0;
        $tmp['topics'][ $topic_id ]['qt'] = $sum_updates;
        $tmp['topics'][ $topic_id ]['ds'] = $days;
        $tmp['topics'][ $topic_id ]['cl'] = isset($tc_topics[$topic_data['info_hash']]) ? $tc_topics[$topic_data['info_hash']]['client'] : '';
        // unset($tc_topics[$topic_data['info_hash']]);
        unset( $previous_data );
        unset( $tmp );
    }
    unset( $topics_data_previous );
    unset( $topics_data );
}

/*	
// получение данных от т.-клиентов
$tor_clients_topics = get_tor_client_data ( $cfg['clients'] );

// получение данных с api.rutracker.org
$forum_ids = array_keys ( $cfg['subsections'] );
$api = new Api ( $cfg['api_url'], $cfg['api_key'] );
$api->get_cat_forum_tree ( $forum_ids );
$topic_ids = $api->get_subsection_data ( $forum_ids );
$api->prepare_topics( $topic_ids, $tor_clients_topics, $forum_ids, $cfg['avg_seeders'] );
*/
$endtime = microtime( true );

Log::append( "Обновление сведений завершено (общее время выполнения: " . round( $endtime - $starttime, 1 ) . " с)." );

?>
