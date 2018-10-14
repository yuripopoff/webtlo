<?php
$start = microtime( true );

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/clients.php';

// получение настроек
if ( ! isset( $cfg ) ) {
    $cfg = get_settings();
}

if ( ! empty( $cfg['clients'] ) ) {
    
    Log::append( "Получение данных от торрент-клиентов..." );
    
    Log::append( "Количество торрент-клиентов: " . count( $cfg['clients'] ) );
    
    // создаём временную таблицу
    Db::query_database( "CREATE TEMP TABLE ClientsNew AS SELECT hs,dl,cl FROM Clients WHERE 0 = 1" );

    // array( [hash] => ( 'status' => status ) )
    // status: 0 - загружается, 1 - раздаётся, -1 - на паузе или стопе
    foreach ( $cfg['clients'] as $client_id => $client_info ) {

        $client = new $client_info['cl'] (
            $client_info['ht'], $client_info['pt'], $client_info['lg'],
            $client_info['pw'], $client_info['cm']
        );

        $count_torrents = 0;

        if ( $client->is_online() ) {

            Log::append ( 'Попытка получить данные о раздачах от торрент-клиента "' . $client_info['cm'] . '"...' );

            $torrents = $client->getTorrents();

            if ( empty( $torrents ) ) {
                continue;
            }

            $count_torrents = count( $torrents );

            $torrents = array_map( function( $e ) use ( $client_id ) {
                $e['cl'] = $client_id;
                return $e;
            }, $torrents );

            $torrents = array_chunk( $torrents, 500, true );

            foreach ( $torrents as $torrents ) {
                $select = Db::combine_set( $torrents );
                Db::query_database( "INSERT INTO temp.ClientsNew (hs,dl,cl) $select" );
                unset( $select );
            }
            unset( $torrents );

        }

        Log::append( $client_info['cm'] . ' (' . $client_info['cl'] . ') - получено раздач: ' . $count_torrents );

    }

    $count_clients = Db::query_database( "SELECT COUNT() FROM temp.ClientsNew", array(), true, PDO::FETCH_COLUMN );

    if ( $count_clients[0] > 0 ) {

        Db::query_database( "INSERT INTO Clients (hs,dl,cl) SELECT hs,dl,cl FROM temp.ClientsNew" );

        Db::query_database( "DELETE FROM Clients WHERE hs NOT IN (
            SELECT Clients.hs FROM temp.ClientsNew LEFT JOIN Clients
            ON temp.ClientsNew.hs  = Clients.hs AND temp.ClientsNew.cl = Clients.cl
            WHERE Clients.hs IS NOT NULL
        )" );

        Log::append( "Поиск раздач из других подразделов..." );

        $stored = Db::query_database(
            "SELECT Clients.hs FROM Clients
            LEFT JOIN Topics ON Topics.hs = Clients.hs
            WHERE Topics.id IS NULL AND Clients.dl IN (1,-1)",
            array(), true, PDO::FETCH_COLUMN
        );
        print_r( $stored );

        // дальше $stored на api, а потом записать в TopicsUntracked

    }
echo convert_seconds(microtime(true)-$start) ."\n";

}

?>
