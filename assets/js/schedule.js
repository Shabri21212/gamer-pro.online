/*
 * График смен: фильтр по сотруднику, подсчёт смен за выделенный период,
 * окно дня и быстрое заполнение «кистью». Разметка — src/pages/shifts.php и shift_chip() в src/ui.php.
 */
(function () {
  "use strict";

  var GP = window.GP || {};
  var on = GP.on;
  var all = GP.all;
  var one = GP.one;
  var grid = document.querySelector("[data-month-grid]");
  if (!grid || !on) return;

  var MONTHS = ["января", "февраля", "марта", "апреля", "мая", "июня", "июля", "августа", "сентября", "октября", "ноября", "декабря"];
  var WEEKDAYS = ["воскресенье", "понедельник", "вторник", "среда", "четверг", "пятница", "суббота"];
  var TYPE_LABEL = { day: "День · 08:00–20:00", night: "Ночь · 20:00–08:00", full: "Сутки · 08:00–08:00" };
  var DRAG_PX = 8;

  var canEdit = grid.getAttribute("data-can-edit") === "1";
  var cells = all("[data-date-key]", grid);
  var summary = one("[data-summary]");
  var empFilter = "";
  var selected = null;
  var drag = null;

  function parseKey(key) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(key || "");
    return m ? { y: +m[1], mo: +m[2] - 1, d: +m[3] } : null;
  }
  function ordered(a, b) {
    return a <= b ? [a, b] : [b, a];
  }
  function rangeLabel(from, to) {
    var a = parseKey(from);
    var b = parseKey(to);
    if (!a || !b) return from + " — " + to;
    if (from === to) return a.d + " " + MONTHS[a.mo];
    return a.mo === b.mo ? a.d + "–" + b.d + " " + MONTHS[a.mo] : a.d + " " + MONTHS[a.mo] + " — " + b.d + " " + MONTHS[b.mo];
  }

  /* ---------- фильтр по сотруднику ---------- */

  function applyFilter() {
    all(".cal-item", grid).forEach(function (el) {
      el.classList.toggle("hidden", empFilter !== "" && el.getAttribute("data-emp") !== empFilter);
    });
    grid.classList.toggle("is-filtered", empFilter !== "");
    all("[data-emp-filter]").forEach(function (btn) {
      btn.setAttribute("aria-pressed", (btn.getAttribute("data-emp-filter") || "") === empFilter ? "true" : "false");
    });
    if (summary) {
      all("[data-sum-emp]", summary).forEach(function (row) {
        row.classList.toggle("is-active", empFilter !== "" && row.getAttribute("data-sum-emp") === empFilter);
      });
    }
  }
  all("[data-emp-filter]").forEach(function (btn) {
    on(btn, "click", function () {
      empFilter = btn.getAttribute("data-emp-filter") || "";
      applyFilter();
    });
  });

  /* ---------- подсчёт смен за выделенный период ---------- */

  function paintRange() {
    var range = selected ? ordered(selected.from, selected.to) : null;
    cells.forEach(function (cell) {
      var key = cell.getAttribute("data-date-key");
      cell.classList.toggle("is-selected", !!range && key >= range[0] && key <= range[1]);
    });
    if (!summary) return;
    all("[data-range-col], [data-range-cell], [data-range-total], [data-range-info]", summary).forEach(function (el) {
      el.hidden = !range;
    });
    var hint = one("[data-range-hint]", summary);
    if (hint) hint.hidden = !!range;
    if (!range) return;

    var totals = {};
    var sum = 0;
    cells.forEach(function (cell) {
      var key = cell.getAttribute("data-date-key");
      if (key < range[0] || key > range[1]) return;
      all(".cal-item:not([data-pending])", cell).forEach(function (el) {
        var id = el.getAttribute("data-emp");
        var weight = Number(el.getAttribute("data-weight") || 1);
        totals[id] = (totals[id] || 0) + weight;
        sum += weight;
      });
    });
    all("[data-sum-emp]", summary).forEach(function (row) {
      var cell = one("[data-range-cell]", row);
      if (cell) cell.textContent = totals[row.getAttribute("data-sum-emp")] || "—";
    });
    var total = one("[data-range-total]", summary);
    if (total) total.textContent = sum || "—";
    var label = one("[data-range-label]", summary);
    if (label) label.textContent = rangeLabel(range[0], range[1]);
  }
  function setRange(from, to) {
    selected = from ? { from: from, to: to } : null;
    paintRange();
  }
  on(summary, "click", function (e) {
    if (e.target.closest("[data-range-clear]")) setRange(null);
  });
  on(document, "keydown", function (e) {
    if (e.key === "Escape" && selected && !document.querySelector("dialog[open]")) setRange(null);
  });

  /* ---------- окно дня ---------- */

  function dayRow(el) {
    var type = el.getAttribute("data-type") || "day";
    var away = el.getAttribute("data-away");
    var name = el.getAttribute("data-emp-name") || "";
    var row = document.createElement("div");
    row.className = "day-row";
    row.setAttribute("data-tone", away ? "" : type);
    row.innerHTML =
      '<span class="day-row-mark" aria-hidden="true"></span><span class="flex min-w-0 flex-1 flex-col"><span class="text-sm font-semibold break-words"></span><span class="text-xs text-muted-foreground"></span></span>';
    var lines = row.querySelectorAll("span span");
    lines[0].textContent = name;
    lines[1].textContent = (TYPE_LABEL[type] || type) + (away ? " · в филиале " + away : "") + (el.hasAttribute("data-live") ? " · идёт сейчас" : "");
    if (canEdit && !away) {
      var form = document.createElement("form");
      form.method = "post";
      form.className = "shrink-0";
      form.innerHTML =
        '<input type="hidden" name="_csrf"><input type="hidden" name="_action" value="delete_shift"><input type="hidden" name="id"><button class="inline-flex h-8 items-center rounded-md px-3 text-sm font-semibold text-muted-foreground transition-colors hover:bg-primary/10 hover:text-primary-ink">Убрать</button>';
      form.elements._csrf.value = GP.csrf || "";
      form.elements.id.value = el.getAttribute("data-shift-id") || "";
      form.querySelector("button").setAttribute("aria-label", "Убрать смену: " + name);
      row.appendChild(form);
    }
    return row;
  }

  function openDay(cell) {
    var dlg = one("[data-day-dialog]");
    if (!dlg || !dlg.showModal) return;
    var key = cell.getAttribute("data-date-key");
    var p = parseKey(key);
    var title = one("[data-dialog-title]", dlg);
    if (title) title.textContent = p ? p.d + " " + MONTHS[p.mo] + ", " + WEEKDAYS[new Date(p.y, p.mo, p.d).getDay()] : key;
    var dateInput = one("[data-dialog-date]", dlg);
    if (dateInput) dateInput.value = key;

    var slots = { day: "0", night: "0" };
    var items = all(".cal-item:not([data-pending])", cell);
    items.forEach(function (el) {
      if (el.hasAttribute("data-away")) return;
      var type = el.getAttribute("data-type");
      var id = el.getAttribute("data-emp");
      if (type !== "night") slots.day = id;
      if (type !== "day") slots.night = id;
    });
    all("[data-day-slot]", dlg).forEach(function (sel) {
      var want = slots[sel.getAttribute("data-day-slot")] || "0";
      sel.value = sel.querySelector('option[value="' + want + '"]') ? want : "0";
    });

    var existing = one("[data-existing]", dlg);
    if (existing) {
      existing.textContent = "";
      items.forEach(function (el) {
        existing.appendChild(dayRow(el));
      });
      if (!items.length) {
        var empty = document.createElement("p");
        empty.className = "text-sm text-muted-foreground";
        empty.textContent = "В этот день смен нет.";
        existing.appendChild(empty);
      }
    }
    GP.openDialog(dlg);
  }

  /* ---------- быстрое заполнение «кистью» ---------- */

  var painting = false;
  var pending = {};
  var touched = {};
  var paintPanel = one("[data-paint-panel]");
  var paintToggle = one("[data-paint-toggle]");
  var paintEmp = null;
  var paintType = "day";

  function syncSlots(cell) {
    var taken = { day: false, night: false };
    all(".cal-item:not([data-away]):not(.is-removing)", cell).forEach(function (el) {
      var type = el.getAttribute("data-type");
      if (type !== "night") taken.day = true;
      if (type !== "day") taken.night = true;
    });
    all("[data-slot-empty]", cell).forEach(function (slot) {
      slot.classList.toggle("hidden", taken[slot.getAttribute("data-slot-empty")]);
    });
  }

  function typeIcon(type) {
    var svg = one('[data-paint-type="' + type + '"] svg');
    return svg ? svg.outerHTML.replace(/class="[^"]*"/, 'class="cal-chip-icon"') : "";
  }

  function renderCell(cell) {
    var key = cell.getAttribute("data-date-key");
    all("[data-pending]", cell).forEach(function (n) {
      n.remove();
    });
    all(".is-removing", cell).forEach(function (n) {
      n.classList.remove("is-removing");
    });
    var box = one(".cal-shifts", cell);
    Object.keys(pending).forEach(function (k) {
      var p = pending[k];
      if (p.d !== key) return;
      // Новая смена заменяет прежнюю этого сотрудника в этот день — прежнюю показываем зачёркнутой.
      var prev = one('.cal-item[data-emp="' + p.e + '"]:not([data-away])', cell);
      if (prev) prev.classList.add("is-removing");
      if (p.op !== "add") return;
      var item = document.createElement("div");
      item.className = "cal-item";
      item.setAttribute("data-pending", "");
      item.setAttribute("data-emp", p.e);
      item.setAttribute("data-emp-name", p.name);
      item.setAttribute("data-type", p.t);
      item.setAttribute("data-weight", p.t === "full" ? "2" : "1");
      item.innerHTML =
        '<span class="cal-chip is-pending" data-tone="' + p.t + '">' + typeIcon(p.t) + '<span class="cal-chip-name"><span class="cal-name-short"></span><span class="cal-name-full"></span></span></span>';
      one(".cal-name-short", item).textContent = p.short;
      one(".cal-name-full", item).textContent = p.name;
      one(".cal-chip", item).title = p.name + " · " + p.hint + " · не сохранено";
      box.appendChild(item);
    });
    syncSlots(cell);
    if (empFilter) applyFilter();
  }

  function syncPaint() {
    var ops = Object.keys(pending).map(function (k) {
      var p = pending[k];
      return p.op === "add" ? { op: "add", d: p.d, e: Number(p.e), t: p.t } : { op: "remove", d: p.d, e: Number(p.e) };
    });
    var count = one("[data-paint-count]");
    var input = one("[data-paint-ops]");
    var save = one("[data-paint-save]");
    if (count) count.textContent = ops.length ? ops.length + " " + GP.plural(ops.length, "изменение", "изменения", "изменений") : "Изменений нет";
    if (input) input.value = JSON.stringify(ops);
    if (save) save.disabled = ops.length === 0;
  }

  function blocked(cell) {
    cell.classList.remove("is-blocked");
    void cell.offsetWidth;
    cell.classList.add("is-blocked");
  }

  function paintCell(cell) {
    var key = cell.getAttribute("data-date-key");
    if (!paintEmp || !paintEmp.id) return blocked(cell);
    // В этот день сотрудник уже стоит в другом филиале — сервер такую смену всё равно отклонит.
    if (one('.cal-item[data-away][data-emp="' + paintEmp.id + '"]', cell)) return blocked(cell);
    var k = key + "|" + paintEmp.id;
    var typeBtn = one('[data-paint-type="' + paintType + '"]');
    var saved = one('.cal-item[data-emp="' + paintEmp.id + '"]:not([data-away]):not([data-pending])', cell);
    if (pending[k] && (pending[k].op === "remove" || pending[k].t === paintType)) {
      delete pending[k];
    } else if (saved && saved.getAttribute("data-type") === paintType) {
      pending[k] = { op: "remove", d: key, e: paintEmp.id };
    } else {
      pending[k] = { op: "add", d: key, e: paintEmp.id, t: paintType, name: paintEmp.name, short: paintEmp.short, hint: typeBtn ? typeBtn.getAttribute("data-hint") || "" : "" };
    }
    touched[key] = cell;
    renderCell(cell);
    syncPaint();
  }

  function setPainting(state) {
    painting = state;
    grid.classList.toggle("is-painting", state);
    if (paintPanel) paintPanel.hidden = !state;
    if (paintToggle) {
      paintToggle.setAttribute("aria-expanded", state ? "true" : "false");
      paintToggle.hidden = state;
    }
    if (state) setRange(null);
  }

  function pickEmp(src) {
    paintEmp = src ? { id: src.getAttribute("data-paint-emp") || src.value, name: src.getAttribute("data-name") || "", short: src.getAttribute("data-short") || "" } : null;
  }
  function press(sel, el) {
    all(sel, paintPanel).forEach(function (b) {
      b.setAttribute("aria-pressed", b === el ? "true" : "false");
    });
  }

  if (paintPanel) {
    var otherSel = one("[data-paint-other]", paintPanel);
    pickEmp(one('[data-paint-emp][aria-pressed="true"]', paintPanel));
    on(paintToggle, "click", function () {
      setPainting(true);
    });
    all("[data-paint-emp]", paintPanel).forEach(function (b) {
      on(b, "click", function () {
        press("[data-paint-emp]", b);
        if (otherSel) otherSel.value = "";
        pickEmp(b);
      });
    });
    on(otherSel, "change", function () {
      if (!otherSel.value) return;
      press("[data-paint-emp]", null);
      pickEmp(otherSel.options[otherSel.selectedIndex]);
    });
    all("[data-paint-type]", paintPanel).forEach(function (b) {
      on(b, "click", function () {
        press("[data-paint-type]", b);
        paintType = b.getAttribute("data-paint-type") || "day";
      });
    });
    on(one("[data-paint-cancel]", paintPanel), "click", function () {
      if (Object.keys(pending).length && !window.confirm("Закрыть без сохранения? Изменения пропадут.")) return;
      pending = {};
      Object.keys(touched).forEach(function (k) {
        renderCell(touched[k]);
      });
      touched = {};
      syncPaint();
      setPainting(false);
    });
    on(one("[data-paint-form]", paintPanel), "submit", function () {
      pending = {};
    });
    on(window, "beforeunload", function (e) {
      if (!Object.keys(pending).length) return;
      e.preventDefault();
      e.returnValue = "";
    });
  }

  /* ---------- ячейки дней: нажатие открывает день, протяжка мышью выделяет период ---------- */

  function activate(cell) {
    if (painting) paintCell(cell);
    else openDay(cell);
  }

  var finePointer = window.matchMedia && window.matchMedia("(pointer: fine)").matches;
  cells.forEach(function (cell) {
    on(cell, "keydown", function (e) {
      if (e.key !== "Enter" && e.key !== " ") return;
      e.preventDefault();
      activate(cell);
    });
    if (!finePointer) {
      on(cell, "click", function () {
        activate(cell);
      });
      return;
    }
    on(cell, "mousedown", function (e) {
      if (e.button !== 0) return;
      e.preventDefault();
      if (painting) {
        paintCell(cell);
        return;
      }
      drag = { anchor: cell.getAttribute("data-date-key"), cell: cell, x: e.clientX, y: e.clientY, moved: false };
    });
    on(cell, "mouseenter", function () {
      if (!drag) return;
      var key = cell.getAttribute("data-date-key");
      if (key !== drag.anchor) {
        drag.moved = true;
        setRange(drag.anchor, key);
      }
    });
  });
  if (finePointer) {
    on(window, "mousemove", function (e) {
      if (!drag || drag.moved) return;
      var dx = e.clientX - drag.x;
      var dy = e.clientY - drag.y;
      if (dx * dx + dy * dy >= DRAG_PX * DRAG_PX) {
        drag.moved = true;
        setRange(drag.anchor, drag.anchor);
      }
    });
    on(window, "mouseup", function () {
      if (!drag) return;
      if (!drag.moved) {
        setRange(null);
        openDay(drag.cell);
      }
      drag = null;
    });
  }
})();
