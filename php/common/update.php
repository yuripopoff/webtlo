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
// создаём временные таблицы
Db::query_database( "CREATE TEMP TABLE UpdateTimeNow AS SELECT id,ud FROM UpdateTime WHERE 0 = 1" );
Db::query_database( "CREATE TEMP TABLE TopicsUpdate AS SELECT id,ss,se,st,rg,qt,ds FROM Topics WHERE 0 = 1" );
Db::query_database( "CREATE TEMP TABLE TopicsRenew AS SELECT id,ss,na,hs,se,si,st,rg,qt,ds FROM Topics WHERE 0 = 1" );

// подключаемся к api
$api = new Api ( $cfg['api_url'], $cfg['api_key'] );

// обновление дерева подразделов
// $api->get_cat_forum_tree();

// все открытые раздачи
$tor_status = array( 0, 2, 3, 8, 10 );

foreach ( $cfg['subsections'] as $forum_id => $subsection ) {

    // получаем данные о раздачах
    $topics_data = $api->get_forum_topics_data( $forum_id );
    if ( empty( $topics_data['result'] ) ) {
        throw new Exception( "Error: Не получены данные о подразделе № " . $forum_id );
    }

    // количество и вес раздач
    $topics_count = count( $topics_data['result'] );
    $topics_size = $topics_data['total_size_bytes'];

    // получаем дату предыдущего обновления
    $update_time = Db::query_database(
        "SELECT ud FROM UpdateTime WHERE id = ?",
        array( $forum_id ), true, PDO::FETCH_COLUMN
    );

    // при первом обновлении
    if ( empty( $update_time ) ) {
        $update_time[0] = 0;
    }

    // если дата текущего обновления совпадает с предыдущей
    if ( $update_time[0] == $topics_data['update_time'] ) {
        Log::append( "Warning: Не требуется обновление для подраздела № " . $forum_id );
        continue;
    }

    // запоминаем время обновления каждого подраздела
    $forums_update_time[ $forum_id ]['ud'] = $topics_data['update_time'];

    // текущее обновления в DateTime
    $current_update_time = new DateTime();
    $current_update_time->setTimestamp( $topics_data['update_time'] );

    // предыдущее обновление в DateTime
    $previous_update_time = new DateTime();
    $previous_update_time->setTimestamp( $update_time[0] )->setTime( 0, 0, 0 );

    // разбиваем result по 500 раздач
    $topics_result = array_chunk( $topics_data['result'], 500, true );
    unset( $topics_data );

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
        // "topic_id": [
        //     "tor_status",    0
        //     "seeders",       1
        //     "reg_time",      2
        //     "tor_size_bytes" 3
        // ]
        foreach ( $topics_result as $topic_id => $topic_data ) {

            if ( empty( $topic_data ) ) {
                continue;
            }

            if ( count( $topic_data ) < 4 ) {
                throw new Exception( "Error: Недостаточно элементов в ответе" );
            }

            if ( ! in_array( $topic_data[0], $tor_status ) ) {
                continue;
            }

            $days_update = 0;
            $sum_updates = 1;
            $sum_seeders = $topic_data[1];

            // запоминаем имеющиеся данные о раздаче в локальной базе
            if ( isset( $topics_data_previous[ $topic_id ] ) ) {
                $previous_data = $topics_data_previous[ $topic_id ];
            }

            // получить для раздачи info_hash и topic_title
            // если новая раздача или перерегистрированная
            if ( empty( $previous_data ) || $previous_data['rg'] != $topic_data[2] ) {
                $db_topics_renew[ $topic_id ]['id'] = $topic_id;
                $db_topics_renew[ $topic_id ]['ss'] = $forum_id;
                $db_topics_renew[ $topic_id ]['na'] = '';
                $db_topics_renew[ $topic_id ]['hs'] = '';
                $db_topics_renew[ $topic_id ]['se'] = $sum_seeders;
                $db_topics_renew[ $topic_id ]['si'] = $topic_data[3];
                $db_topics_renew[ $topic_id ]['st'] = $topic_data[0];
                $db_topics_renew[ $topic_id ]['rg'] = $topic_data[2];
                $db_topics_renew[ $topic_id ]['qt'] = $sum_updates;
                $db_topics_renew[ $topic_id ]['ds'] = $days_update;
                // удаляем перерегистрированую раздачу
                if ( isset( $previous_data['id'] ) ) {
                    $topics_delete[] = $topic_id;
                }
                unset( $previous_data );
                continue;
            }

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

            $db_topics_update[ $topic_id ]['id'] = $topic_id;
            $db_topics_update[ $topic_id ]['ss'] = $forum_id;
            $db_topics_update[ $topic_id ]['se'] = $sum_seeders;
            $db_topics_update[ $topic_id ]['st'] = $topic_data[0];
            $db_topics_update[ $topic_id ]['rg'] = $topic_data[2];
            $db_topics_update[ $topic_id ]['qt'] = $sum_updates;
            $db_topics_update[ $topic_id ]['ds'] = $days_update;
            // "0" - не храню, "1" - храню (раздаю), "-1" - храню (качаю), "-2" - из других подразделов
            // $db_topics_update[ $topic_id ]['dl'] = isset( $tc_topics[ $topic_data['info_hash'] ] )
            //     ? $stored
            //         ? empty( $tc_topics[ $topic_data['info_hash'] ]['status'] )
            //             ? -1
            //             : 1
            //         : -2
            //     : 0;
            // $db_topics_update[ $topic_id ]['cl'] = isset( $tc_topics[ $topic_data['info_hash'] ] )
            //     ? $tc_topics[ $topic_data['info_hash'] ]['client']
            //     : '';
            unset( $previous_data );
// echo "each topic: " . memory_get_usage() . "\n";
        }
        unset( $topics_data_previous );

// echo "after topic_result: " . memory_get_usage() . "\n";

        if ( ! empty( $db_topics_renew ) ) {
            //
            $topics_renew_ids = array_keys( $db_topics_renew );
            $in = str_repeat( '?,', count( $topics_renew_ids ) - 1 ) . '?';
            $topics_data = $api->get_tor_topic_data( $topics_renew_ids );
            unset( $topics_renew_ids );
            if ( empty( $topics_data ) ) {
                throw new Exception( "Error: Не получены дополнительные данные о раздачах" );
            }
            foreach ( $topics_data as $topic_id => $topic_data ) {
                if ( empty( $topic_data ) ) {
                    continue;
                }
                if ( isset( $db_topics_renew[ $topic_id ] ) ) {
                    $db_topics_renew[ $topic_id ]['hs'] = $topic_data['info_hash'];
                    $db_topics_renew[ $topic_id ]['na'] = $topic_data['topic_title'];
                }
            }
            unset( $topics_data );
            // вставка данных в базу
            $select = Db::combine_set( $db_topics_renew );
            unset( $db_topics_renew );
            Db::query_database( "INSERT INTO temp.TopicsRenew $select" );
            unset( $select );
// print_r( count( $db_topics_renew ) . "\n" );
        }
        unset( $db_topics_renew );

        // пишем данные о топиках в базу
        if ( isset( $db_topics_update ) ) {
            $select = Db::combine_set( $db_topics_update );
            unset( $db_topics_update );
            Db::query_database( "INSERT INTO temp.TopicsUpdate $select" );
            unset( $select );
        }
        unset( $db_topics_update );

    }
// echo "before all topic_result: " . memory_get_usage() . "\n";
    unset( $topics_result );
// echo "before unset topic_result: " . memory_get_usage() . "\n";
}

// удаляем перерегистрированные раздачи
// чтобы очистить значения сидов для старой раздачи
if ( isset( $topics_delete ) ) {
    $topics_delete = array_chunk( $topics_delete, 500 );
    foreach ( $topics_delete as $topics_delete ) {
        $in = str_repaet( '?,', count( $topics_delete ) - 1 ) . '?';
        Db::query_database( "DELETE FROM Topics WHERE id IN ($in)", $topics_delete );
    }
}

$count_update = Db::query_database( "SELECT COUNT() FROM temp.TopicsUpdate", array(), true, PDO::FETCH_COLUMN );
$count_renew = Db::query_database( "SELECT COUNT() FROM temp.TopicsRenew", array(), true, PDO::FETCH_COLUMN );
if ( $count_update[0] > 0 || $count_renew[0] > 0 ) {
    Log::append ( "Запись в базу данных сведений о раздачах..." );
    // переносим данные в основную таблицу
    $forums_ids = array_keys( $forums_update_time );
    $in = str_repeat( '?,', count( $forums_ids ) - 1 ) . '?';
    Db::query_database( "INSERT INTO Topics (id,ss,se,st,rg,qt,ds) SELECT * FROM temp.TopicsUpdate" );
    Db::query_database( "INSERT INTO Topics (id,ss,na,hs,se,si,st,rg,qt,ds) SELECT * FROM temp.TopicsRenew" );
    Db::query_database(
        "DELETE FROM Topics WHERE id IN (
            SELECT Topics.id FROM Topics
            LEFT JOIN temp.TopicsUpdate ON Topics.id = temp.TopicsUpdate.id
            LEFT JOIN temp.TopicsRenew ON Topics.id = temp.TopicsRenew.id
            WHERE temp.TopicsUpdate.id IS NULL AND temp.TopicsRenew.id IS NULL AND Topics.ss IN ($in)
        )",
        $forums_ids
    );
    // время последнего обновления
    $forums_update_time = array_chunk( $forums_update_time, 500, true );
    foreach ( $forums_update_time as $forums_update_time ) {
        $select = Db::combine_set( $forums_update_time );
        Db::query_database( "INSERT INTO temp.UpdateTimeNow $select");
        unset( $select );
    }
    Db::query_database( "INSERT INTO UpdateTime (id,ud) SELECT id,ud FROM temp.UpdateTimeNow" );
}

$endtime = microtime( true );

// echo "runtime: " . round( $endtime - $starttime, 1 ) . "\n";

Log::append( "Обновление сведений завершено (общее время выполнения: " . round( $endtime - $starttime, 1 ) . " с)." );
echo Log::get( "\n" );

?>
