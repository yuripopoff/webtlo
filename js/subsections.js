
/* всё про работу с подразделами */

$(document).ready(function () {

	// последний выбранный подраздел
	var ss_change;

	// загрузка данных о выбранном подразделе на главной
	$("#subsections").selectmenu({
		width: "calc(100% - 36px)",
		change: function (event, ui) {
			getFilteredTopics();
			Cookies.set('saved_forum_id', ui.item.value);
		},
		create: function (event, ui) {
			if (typeof (Cookies.get('saved_forum_id')) !== "undefined") {
				$("#subsections").val(parseInt(Cookies.get('saved_forum_id')));
				$("#subsections").selectmenu("refresh");
			}
		},
		open: function (event, ui) {
			var height = $("#subsections-menu").height() >= 399 ? 400 : 'auto';
			$("#subsections-menu").css("height", height);
			var active = $("#subsections-button").attr("aria-activedescendant");
			$("#subsections-menu").closest("ul")
				.find("div[role=option]")
				.each(function () {
					$(this).css({ "font-weight": "normal" });
				});
			$("#" + active).css({ "font-weight": "bold" });
		},
	});

	// получение свойств выбранного подраздела
	$("#list-ss").on("change", function () {
		var data = $("#list-ss :selected").attr("data");
		data = data.split('|');
		var client = $("#ss-client option[value=" + data[0] + "]").val();
		if (client) {
			$("#ss-client [value=" + client + "]").prop("selected", "selected");
		}
		else {
			$("#ss-client :first").prop("selected", "selected");
		}
		$("#ss-label").val(data[1]);
		$("#ss-folder").val(data[2]);
		$("#ss-link").val(data[3]);
		if (data[4]) {
			$("#ss-sub-folder").val(data[4]);
		} else {
			$("#ss-sub-folder :first").prop("selected", "selected");
		}
		$("#ss-hide-topics [value=" + data[5] + "]").prop("selected", "selected");
		var ss_change = $(this).val();
		$("#ss-id").val(ss_change);
	});

	// изменение свойств подраздела
	$("#ss-prop").on("focusout", function () {
		var cl = $("#ss-client :selected").val();
		var lb = $("#ss-label").val();
		var fd = $("#ss-folder").val();
		var ln = $("#ss-link").val();
		var sub_folder = $("#ss-sub-folder").val();
		var hide_topics = $("#ss-hide-topics :selected").val();
		$("#list-ss option[value=" + ss_change + "]")
			.attr("data", cl + "|" + lb + "|" + fd + "|" + ln + "|" + sub_folder + "|" + hide_topics);
	});

	// добавить подраздел
	$("#ss-add").autocomplete({
		source: 'php/actions/get_list_subsections.php',
		delay: 1000,
		select: addSubsection
	}).on("focusout", function () {
		$(this).val("");
	});

	// удалить подраздел
	$("#ss-del").on("click", function () {
		var forum_id = $("#list-ss").val();
		if (forum_id) {
			var i = $("#list-ss :selected").index();
			$("#list-ss :selected").remove();
			$("#subsections_stored [value=" + forum_id + "]").remove();
			$("#reports_list_stored [value=" + forum_id + "]").remove();
			var q = $("select[id=list-ss] option").size();
			if (q == 0) {
				$("#ss-prop .ss-prop, #list-ss").val('').prop("disabled", true);
				$("#ss-client :first").prop("selected", "selected");
			} else {
				q == i ? i : i++;
				$("#list-ss :nth-child(" + i + ")").prop("selected", "selected").change();
			}
			$("#subsections").selectmenu("refresh");
			$("#reports_list").selectmenu("refresh");
			getFilteredTopics();
		}
	});

	// при загрузке выбрать первый подраздел в списке
	if ($("select[id=list-ss] option").size() > 0) {
		$("#list-ss :first").prop("selected", "selected").change();
	} else {
		$("#ss-prop .ss-prop").prop("disabled", true);
	}

});

function addSubsection(event, ui) {
	if (ui.item.value < 0) {
		ui.item.value = '';
		return false;
	}
	var lb = ui.item.label;
	var label = lb.replace(/.* » (.*)$/, '$1');
	var vl = ui.item.value;
	var q = 0;
	$("#list-ss option").each(function () {
		var val = $(this).val();
		if (vl == val) {
			q = 1;
		}
	});
	if (q != 1) {
		$("#list-ss").append('<option value="' + vl + '" data="0|' + label + '||||0">' + lb + '</option>');
		$("#subsections_stored").append('<option value="' + vl + '">' + lb + '</option>');
		$("#reports_list_stored").append('<option value="' + vl + '">' + lb + '</option>');
		$("#ss-prop .ss-prop, #list-ss").prop("disabled", false);
		$("#ss-id").prop("disabled", true);
	}
	$("#list-ss option[value=" + vl + "]").prop("selected", "selected").change();
	ui.item.value = '';
	doSortSelect("list-ss");
	doSortSelect("subsections_stored");
	doSortSelect("reports_list_stored");
	$("#subsections").selectmenu("refresh");
	$("#reports_list").selectmenu("refresh");
}

function getForums() {
	var forums = {};
	$("#list-ss option").each(function () {
		var value = $(this).val();
		if (value != 0) {
			var text = $(this).text();
			var data = $(this).attr("data");
			data = data.split("|");
			forums[value] = {
				"id": value,
				"na": text,
				"cl": data[0],
				"lb": data[1],
				"fd": data[2],
				"ln": data[3],
				"sub_folder": data[4],
				"hide_topics": data[5]
			};
		}
	});
	return forums;
}
