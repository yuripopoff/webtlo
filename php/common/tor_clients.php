<?php

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
    Db::query_database( "CREATE TEMP TABLE ClientsNew AS SELECT * FROM Clients WHERE 0 = 1" );

    // array( [hash] => ( 'status' => status ) )
    // status: 0 - загружается, 1 - раздаётся, -1 - на паузе или стопе
    foreach ( $cfg['clients'] as $client_id => $client_info ) {

        $client = new $client_info['cl'] (
            $client_info['ht'], $client_info['pt'], $client_info['lg'],
            $client_info['pw'], $client_info['cm']
        );

        if ( $client->is_online() ) {

            Log::append ( 'Попытка получить данные о раздачах от торрент-клиента "' . $client_info['cm'] . '"...' );

            $data = $client->getTorrents();

            if ( empty( $data ) ) {
                continue;
            }

            $data = array_chunk( $data, 500, true );

            foreach ( $data as $data ) {
                $select = Db::combine_set( $data );
                Db::query_database( "INSERT INTO temp.ClientsNew $select" );
                unset( $select );
            }
            unset( $data );

        }

        Log::append( $client_info['cm'] . ' (' . $client_info['cl'] . ') - получено раздач: ' . count( $data ) );

    }

    $count_clients = Db::query_database( "SELECT COUNT() FROM temp.ClientsNew", array(), true, PDO::FETCH_COLUMN );
    
    if ( $count_clients[0] > 0 ) {
    
        Db::query_database( "INSERT INTO Clients SELECT * FROM temp.ClientsNew" );
    
        Db::query_database( "DELETE FROM Clients WHERE id NOT IN (
            SELECT Clients.id FROM temp.ClientsNew LEFT JOIN Clients
            ON temp.ClientsNew.hs  = Clients.hs AND temp.ClientsNew.cl = Clients.cl
            WHERE Clients.id IS NOT NULL
        )" );
    
    }

}

?>
