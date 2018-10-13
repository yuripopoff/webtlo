<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/api.php';

// получение настроек
if ( ! isset( $cfg ) ) {
    $cfg = get_settings();
}

// подключаемся к api
if ( ! isset( $api ) ) {
    $api = new Api ( $cfg['api_url'], $cfg['api_key'] );
}

// обновление дерева подразделов
$forum_tree_update = Db::query_database(
    "SELECT julianday('now') - julianday( datetime(ud, 'unixepoch') ) FROM UpdateTime WHERE id = ?",
    array( 8888 ), true, PDO::FETCH_COLUMN
);

if ( empty( $forum_tree_update ) || $forum_tree_update[0] > 1 ) {

    $forum_tree = $api->get_cat_forum_tree();

    if ( empty( $forum_tree['result'] ) ) {
        throw new Exception( "Error: Не удалось получить дерево подразделов" );
    }

    $forum_tree_update_current = $forum_tree['update_time'];

    foreach ( $forum_tree['result']['c'] as $cat_id => $cat_title ) {
        foreach ( $data['result']['tree'][ $cat_id ] as $forum_id => $subforum ) {
            // разделы
            $forum_title = $cat_title.' » '.$data['result']['f'][ $forum_id ];
            $forums[ $forum_id ] = array(
                'na' => $forum_title
            );
            // подразделы
            foreach ( $subforum as $subforum_id ) {
                $subforum_title = $cat_title.' » '.$data['result']['f'][ $forum_id ].' » '.$data['result']['f'][ $subforum_id ];
                $forums[ $subforum_id ] = array(
                    'na' => $subforum_title
                );
            }
        }
    }
    unset( $forum_tree );

    // создаём временную таблицу
    Db::query_database( 'CREATE TEMP TABLE ForumsNew AS SELECT * FROM Forums WHERE 0 = 1' );

    // отправляем в базу данных
    $forums = array_chunk( $forums, 500, true );

    foreach ( $forums as $forums ) {
        $select = Db::combine_set( $forums );
        Db::query_database( "INSERT INTO temp.ForumsNew (id,na) $select" );
        unset( $select );
    }
    unset( $forums );

    Log::append( "Обновление дерева подразделов..." );

    Db::query_database( 'INSERT INTO Forums (id,na) SELECT id,na FROM temp.ForumsNew' );

    Db::query_database( 'DELETE FROM Forums WHERE id IN (
        SELECT Forums.id FROM Forums
        LEFT JOIN temp.ForumsNew ON Forums.id = temp.ForumsNew.id
        WHERE temp.ForumsNew.id IS NULL
    )');

    // время обновления дера подразделов
    Db::query_database(
        "INSERT INTO UpdateTime (id,ud) SELECT 8888,?",
        array( $forum_tree_update_current )
    );

    unset( $forum_tree_update_current );
    unset( $forum_tree_update );

}

?>
