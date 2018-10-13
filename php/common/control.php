<?php

// регулировка раздач
function topics_control( $topics, $tc_topics, $ids, $rule, $tcs = array() ) {
	
	$ids = array_flip( $ids );
	
	// выбираем раздачи для остановки
	foreach( $topics as $topic_id => $topic ) {
		
		// если нет такой раздачи или идёт загрузка раздачи, идём дальше
		if( empty( $tc_topics[$ids[$topic_id]]['status'] ) ) continue;
		$client = $tc_topics[$ids[$topic_id]];
		
		// учитываем себя
		$topic['seeders'] -= $topic['seeders'] ? $client['status'] : 0;
		// находим значение личей
		$leechers = $rule['leechers'] ? $topic['leechers'] : 0;
		// находим значение пиров
		$peers = $topic['seeders'] + $leechers;
		// учитываем вновь прибывшего "лишнего" сида
		$peers += $topic['seeders'] && $peers == $rule['peers'] && $client['status'] == 1 ? 1 : 0;
		
		// стопим только, если есть сиды
		if( ( $peers > $rule['peers'] || !$rule['no_leechers'] && !$topic['leechers'] ) && $topic['seeders'] ) {
			if( $client['status'] == 1 )
				$hashes[$client['client']]['stop'][] = $ids[$topic_id];
		} else {
			if( $client['status'] == -1 )
				$hashes[$client['client']]['start'][] = $ids[$topic_id];
		}
	}
	
	if( empty( $hashes ) )
		throw new Exception( 'Раздачи не нуждаются в регулировании.' );
	
	// выполняем запуск/остановку раздач
	foreach( $tcs as $cm => $tc ) {
		if( empty( $hashes[$cm] ) ) continue;
		$client = new $tc['cl'] ( $tc['ht'], $tc['pt'], $tc['lg'], $tc['pw'], $tc['cm'] );
		if( $client->is_online() ) {
			// запускаем
			if( !empty( $hashes[$cm]['start'] ) ) {
				$q = count( $hashes[$cm]['start'] );
				$hashes[$cm]['start'] = array_chunk( $hashes[$cm]['start'], 100 );
				foreach( $hashes[$cm]['start'] as $start ) {
					$client->torrentStart( $start );
				}
				Log::append( "Запрос на запуск раздач торрент-клиенту \"$cm\" отправлен ($q)." );
			}
			// останавливаем
			if( !empty( $hashes[$cm]['stop'] ) ) {
				$q = count( $hashes[$cm]['stop'] );
				$hashes[$cm]['stop'] = array_chunk( $hashes[$cm]['stop'], 100 );
				foreach( $hashes[$cm]['stop'] as $stop ) {
					$client->torrentStop( $stop );
				}
				Log::append( "Запрос на остановку раздач торрент-клиенту \"$cm\" отправлен ($q)." );
			}
		} else {
			Log::append( "Регулировка раздач не выполнена для торрент-клиента \"$cm\"." );
			continue;
		}
	}
	
}

?>
