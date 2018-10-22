<?php

try {

    $starttime = microtime(true);

    include_once dirname(__FILE__) . '/../common.php';
    include_once dirname(__FILE__) . '/../torrenteditor.php';
    include_once dirname(__FILE__) . '/../classes/download.php';

    $result = "";

    // проверка данных
    if (empty($_POST['topics_ids'])) {
        $result = "Выберите раздачи";
        throw new Exception();
    }

    if (isset($_POST['replace_passkey'])) {
        $replace_passkey = $_POST['replace_passkey'];
    }

    // парсим настройки
    if (isset($_POST['cfg'])) {
        parse_str($_POST['cfg']);
    }

    if (empty($api_key)) {
        $result = "В настройках не указан хранительский ключ API";
        throw new Exception();
    }

    if (empty($user_id)) {
        $result = "В настройках не указан хранительский ключ ID";
        throw new Exception();
    }

    $retracker = isset($retracker) ? 1 : 0;
    $tor_for_user = isset($tor_for_user) ? 1 : 0;
    $forum_id = isset($_POST['forum_id']) ? $_POST['forum_id'] : 0;
    parse_str($_POST['topics_ids']);

    // выбор каталога
    $torrent_files_path = empty($replace_passkey) ? $savedir : $dir_torrents;

    if (empty($torrent_files_path)) {
        $result = "В настройках не указан каталог для скачивания торрент-файлов";
        throw new Exception();
    }

    // дополнительный слэш в конце каталога
    if (
        !empty($torrent_files_path)
        && !in_array(substr($torrent_files_path, -1), array('\\', '/'))
    ) {
        $torrent_files_path .= strpos($torrent_files_path, '/') === false ? '\\' : '/';
    }

    // создание подкаталога
    if (
        empty($replace_passkey)
        && isset($savesubdir)
    ) {
        $torrent_files_path .= 'tfiles_' . $forum_id . '_' . time() . substr($torrent_files_path, -1);
    }

    // создание каталогов
    if (!mkdir_recursive($torrent_files_path)) {
        $result = "Не удалось создать каталог \"$torrent_files_path\": неверно указан путь или недостаточно прав";
        throw new Exception();
    }

    // прокси
    $activate_forum = isset($proxy_activate_forum) ? 1 : 0;
    $activate_api = isset($proxy_activate_api) ? 1 : 0;
    $proxy_address = "$proxy_hostname:$proxy_port";
    $proxy_auth = "$proxy_login:$proxy_paswd";
    Proxy::options(
        $activate_forum,
        $activate_api,
        $proxy_type,
        $proxy_address,
        $proxy_auth
    );

    // шаблон для сохранения
    $torrent_files_path_pattern = "$torrent_files_path/[webtlo].t%s.torrent";
    if (PHP_OS == 'WINNT') {
        $torrent_files_path_pattern = mb_convert_encoding(
            $torrent_files_path_pattern,
            'Windows-1251',
            'UTF-8'
        );
    }

    Log::append($replace_passkey
        ? 'Выполняется скачивание торрент-файлов с заменой Passkey...'
        : 'Выполняется скачивание торрент-файлов...'
    );

    // скачивание торрент-файлов
    $download = new Download($forum_url, $api_key, $user_id);
    foreach ($topics_ids as $topic_id) {
        $data = $download->get_torrent_file($topic_id, $retracker);
        if ($data === false) {
            continue;
        }
        // меняем пасскей
        if ($replace_passkey) {
            $torrent = new Torrent();
            if ($torrent->load($data) == false) {
                Log::append("Error: $torrent->error ($topic_id).");
                break;
            }
            $trackers = $torrent->getTrackers();
            foreach ($trackers as &$tracker) {
                $tracker = preg_replace('/(?<==)\w+$/', $passkey, $tracker);
                if ($tor_for_user) {
                    $tracker = preg_replace('/\w+(?==)/', 'pk', $tracker);
                }
            }
            unset($tracker);
            $torrent->setTrackers($trackers);
            $data = $torrent->bencode();
        }
        // сохранить в каталог
        $file_put_contents = file_put_contents(
            sprintf(
                $torrent_files_path_pattern,
                $topic_id
            ),
            $data
        );
        if ($file_put_contents === false) {
            Log::append("Произошла ошибка при сохранении торрент-файла ($topic_id)");
            continue;
        }
        $torrent_files_downloaded[] = $topic_id;
    }

    $torrent_files_downloaded = count($torrent_files_downloaded);

    $endtime = microtime(true);

    $result = "Сохранено в каталоге \"$torrent_files_path\": $torrent_files_downloaded шт. за " . convert_seconds($endtime - $starttime);

    echo json_encode(array(
        'log' => Log::get(),
        'result' => $result,
    ));

} catch (Exception $e) {

    Log::append($e->getMessage());
    echo json_encode(array(
        'log' => Log::get(),
        'result' => $result,
    ));

}
