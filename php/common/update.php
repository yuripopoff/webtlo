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
/*
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
*/
// создаём временную таблицу
Db::query_database( "CREATE TEMP TABLE Topics1 AS SELECT * FROM Topics WHERE 0 = 1" );

// получаем дату предыдущего обновления
$update_topics = Db::query_database( "SELECT ud FROM Other", array(), true, PDO::FETCH_COLUMN );
$previous_update_time = new DateTime();
$previous_update_time->setTimestamp( $update_topics[0] )->setTime( 0, 0, 0 );

// подключаемся к api
$api = new Api ( $cfg['api_url'], $cfg['api_key'] );

// обновление дерева подразделов
// $api->get_cat_forum_tree();

foreach ( $cfg['subsections'] as $forum_id => $subsection ) {

    // получаем данные о раздачах
    $topics_data = $api->get_forum_topics_data( $forum_id );
    if ( empty( $topics_data['result'] ) ) {
        throw new Exception( "Error: Не получены данные о подразделе № " . $forum_id );
    }

    // количество и вес раздач
    $topics_count = count( $topics_data['result'] );
    $topics_size = $topics_data['total_size_bytes'];

    // дата текущего обновления
    if ( empty( $current_update_time ) ) {
        $current_update_time = new DateTime();
        $current_update_time->setTimestamp( $topics_data['update_time'] );
    }

    // если дата текущего обновления совпадает с предыдущим
    if ( $update_topics[0] == $topics_data['update_time'] ) {
        Log::append( "Warning: Обновление не требуется" );
        continue;
    }

    // "topic_id": [
    //     "tor_status",    0
    //     "seeders",       1
    //     "reg_time",      2
    //     "tor_size_bytes" 3
    // ]

    // разбиваем result по 500 раздач
    $topics_result = array_chunk( $topics_data['result'], 500, true );

    foreach ( $topics_result as $topics_result ) {

        // получаем данные о раздачах за предыдущее обновление
        if ( $cfg['avg_seeders'] ) {
            $topics_ids = array_keys( $topics_result );
            $in = str_repeat( '?,', count( $topics_ids ) - 1 ) . '?';
            $topics_data_previous = Db::query_database(
                "SELECT id,se,rg,qt,ds FROM Topics WHERE id IN ($in)",
                $topics_ids, true, PDO::FETCH_ASSOC|PDO::FETCH_UNIQUE
            );
            unset( $topics_ids );
        }

        // разбираем раздачи
        foreach ( $topics_result as $topic_id => $topic_data ) {

            if ( empty( $topic_data ) ) {
                continue;
            }

            if ( count( $topic_data ) < 4 ) {
                throw new Exception( "Error: Недостаточно элементов в ответе" );
            }

            // запоминаем имеющиеся данные о раздаче в локальной базе
            if ( isset( $topics_data_previous[ $topic_id ] ) ) {
                $previous_data = $topics_data_previous[ $topic_id ];
            }

            // получить для раздачи info_hash и topic_title
            // если новая раздача или перерегистрированная
            if ( empty( $previous_data ) || $previous_data['rg'] != $topic_data[2] ) {
                $topics_data_add[] = $topic_id;
            }

            $days_update = 0;
            $sum_updates = 1;
            $sum_seeders = $topic_data[1];

            // алгоритм нахождения среднего значения сидов
            if ( $cfg['avg_seeders'] && isset( $previous_data ) ) {
                // ??? empty()
                if ( empty( $previous_data['rg'] ) || $previous_data['rg'] == $topic_data[2] ) {
                    // переносим старые значения
                    $days_update = $previous_data['ds'];
                    // разница в днях между обновлениями сведений
                    if ( ! empty( $previous_update_time ) )  {
                        $days_diff = $current_update_time->diff( $previous_update_time )->format( '%d' );
                    }
                    // по прошествии дня
                    if ( ! empty( $days_diff ) && $days_diff > 0 ) {
                        $days_update++;
                    } else {
                        $sum_updates += $previous_data['qt'];
                        $sum_seeders += $previous_data['se'];
                    }
                } else {
                    // удалять перерегистрированные раздачи
                    // чтобы очистить значения сидов для старой раздачи
                    $topics_delete[] = $topic_id;
                }
            }


            $tmp[ $topic_id ]['id'] = $topic_id;
            $tmp[ $topic_id ]['ss'] = $forum_id;
            $tmp[ $topic_id ]['na'] = empty( $previous_data['na'] ) ? '' : $previous_data['na'];
            $tmp[ $topic_id ]['hs'] = empty( $previous_data['na'] ) ? '' : $previous_data['hs'];
            $tmp[ $topic_id ]['se'] = $sum_seeders;
            $tmp[ $topic_id ]['si'] = $topic_data[3];
            $tmp[ $topic_id ]['st'] = $topic_data[0];
            $tmp[ $topic_id ]['rg'] = $topic_data[2];
            // "0" - не храню, "1" - храню (раздаю), "-1" - храню (качаю), "-2" - из других подразделов
            // $tmp[ $topic_id ]['dl'] = isset( $tc_topics[ $topic_data['info_hash'] ] )
            //     ? $stored
            //         ? empty( $tc_topics[ $topic_data['info_hash'] ]['status'] )
            //             ? -1
            //             : 1
            //         : -2
            //     : 0;
            $tmp[ $topic_id ]['qt'] = $sum_updates;
            $tmp[ $topic_id ]['ds'] = $days_update;
            // $tmp[ $topic_id ]['cl'] = isset( $tc_topics[ $topic_data['info_hash'] ] )
            //     ? $tc_topics[ $topic_data['info_hash'] ]['client']
            //     : '';
            unset( $previous_data );
echo "each topic: " . memory_get_usage() . "\n";
        }
        unset( $topics_data_previous );

echo "after topic_result: " . memory_get_usage() . "\n";

        if ( ! empty( $topics_data_add ) ) {
            //
            $in = str_repeat( '?,', count( $topics_data_add ) - 1 ) . '?';
            $topics_data = $api->get_tor_topic_data( $topics_data_add );
            if ( empty( $topics_data ) ) {
                throw new Exception( "Error: Не получены дополнительные данные о раздачах" );
            }
            foreach ( $topics_data as $topic_id => $topic_data ) {
                if ( empty( $topic_data ) ) {
                    continue;
                }
                if ( isset( $tmp[ $topic_id ] ) ) {
                    $tmp[ $topic_id ]['hs'] = $topic_data['info_hash'];
                    $tmp[ $topic_id ]['na'] = $topic_data['topic_title'];
                }
            }
        }
        unset( $topics_data_add );

        // пишем данные о топиках в базу
        // if ( isset( $tmp ) ) {
        //     $select = Db::combine_set( $tmp );
        //     unset( $tmp );
        //     Db::query_database( "INSERT INTO temp.Topics1 $select" );
        //     unset( $select );
        // }
        unset( $tmp );

    }
echo "before all topic_result: " . memory_get_usage() . "\n";
    unset( $topics_result );
echo "before unset topic_result: " . memory_get_usage() . "\n";
return;
}

return;

// удаляем перерегистрированные раздачи
// чтобы очистить значения сидов для старой раздачи
if ( ! empty( $topics_delete ) ) {
    $topics_delete = array_chunk( $topics_delete, 500 );
    foreach ( $topics_delete as $topics_delete ) {
        $in = str_repaet( '?,', count( $topics_delete ) - 1 ) . '?';
        Db::query_database( "DELETE FROM Topics WHERE id IN ($in)", $topics_delete );
    }
}

$q = Db::query_database( "SELECT COUNT() FROM temp.Topics1", array(), true, PDO::FETCH_COLUMN );
if ( $q[0] > 0 ) {
    Log::append ( "Запись в базу данных сведений о раздачах..." );
    $in = str_repeat( '?,', count( $subsec ) - 1 ) . '?';
    Db::query_database( "INSERT INTO Topics SELECT * FROM temp.Topics1" );
    Db::query_database(
        "DELETE FROM Topics WHERE id IN (
            SELECT Topics.id FROM Topics
            LEFT JOIN temp.Topics1 ON Topics.id = temp.Topics1.id
            WHERE temp.Topics1.id IS NULL AND ( Topics.ss IN ($in) OR Topics.dl = -2 )
        )",
        $subsec
    );
}

// время последнего обновления
Db::query_database(
    'UPDATE UpdateTime SET update_topics = ? WHERE id = 0',
    array( $current_update_time->format( 'U' ) )
);

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
