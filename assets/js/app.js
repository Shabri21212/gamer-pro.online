(function () {
  "use strict";

  var MONTHS = ["января","февраля","марта","апреля","мая","июня","июля","августа","сентября","октября","ноября","декабря"];

  function on(el, ev, fn, opt) {
    if (el) el.addEventListener(ev, fn, opt);
  }

  document.querySelectorAll("[data-menu]").forEach(function (wrap) {
    var btn = wrap.querySelector("[data-menu-btn]");
    var panel = wrap.querySelector("[data-menu-panel]");
    if (!btn || !panel) return;
    on(btn, "click", function (e) {
      e.stopPropagation();
      panel.classList.toggle("hidden");
    });
    on(document, "click", function () {
      panel.classList.add("hidden");
    });
  });

  var drawer = document.querySelector("[data-nav-drawer]");
  document.querySelectorAll("[data-open-nav]").forEach(function (b) {
    on(b, "click", function () {
      if (drawer) drawer.classList.remove("hidden");
    });
  });
  document.querySelectorAll("[data-close-nav]").forEach(function (b) {
    on(b, "click", function () {
      if (drawer) drawer.classList.add("hidden");
    });
  });

  document.querySelectorAll("[data-theme-toggle]").forEach(function (btn) {
    function sync() {
      var dark = document.documentElement.classList.contains("dark");
      btn.setAttribute("aria-label", dark ? "Включить светлую тему" : "Включить тёмную тему");
      btn.innerHTML = dark
        ? '<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>'
        : '<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>';
    }
    sync();
    on(btn, "click", function () {
      var next = !document.documentElement.classList.contains("dark");
      document.documentElement.classList.toggle("dark", next);
      document.documentElement.classList.toggle("light", !next);
      document.documentElement.style.colorScheme = next ? "dark" : "light";
      try {
        localStorage.setItem("gamerpro-theme", next ? "dark" : "light");
      } catch (e) {}
      sync();
    });
  });

  document.querySelectorAll("[data-toggle-pass]").forEach(function (btn) {
    on(btn, "click", function () {
      var input = btn.parentElement.querySelector("input");
      if (!input) return;
      input.type = input.type === "password" ? "text" : "password";
    });
  });

  document.querySelectorAll("[data-filter-table]").forEach(function (input) {
    on(input, "input", function () {
      var q = input.value.toLowerCase().trim();
      document.querySelectorAll("[data-row-text]").forEach(function (row) {
        row.classList.toggle("hidden", q !== "" && (row.getAttribute("data-row-text") || "").indexOf(q) === -1);
      });
    });
  });

  document.querySelectorAll("[data-wiki-search]").forEach(function (input) {
    on(input, "input", function () {
      var q = input.value.toLowerCase().trim();
      document.querySelectorAll("[data-wiki-item]").forEach(function (a) {
        a.parentElement.classList.toggle("hidden", q !== "" && (a.getAttribute("data-text") || "").indexOf(q) === -1);
      });
    });
  });

  document.querySelectorAll("[data-tabs]").forEach(function (bar) {
    bar.querySelectorAll("[data-tab]").forEach(function (btn) {
      on(btn, "click", function () {
        var id = btn.getAttribute("data-tab");
        document.querySelectorAll("[data-tab-panel]").forEach(function (p) {
          p.classList.toggle("hidden", p.getAttribute("data-tab-panel") !== id);
        });
      });
    });
  });

  function phoneSearchDigits(raw) {
    var digits = String(raw || "").replace(/\D/g, "");
    if (!digits || digits === "7" || digits === "8") return "";
    if (digits.charAt(0) === "7" || digits.charAt(0) === "8") digits = digits.slice(1);
    return digits;
  }

  function applyReviewPhoneFilter() {
    var table = document.querySelector("[data-review-table]");
    if (!table) return;
    var phoneInput = document.querySelector("[data-review-phone]");
    var extra = document.querySelector("[data-review-filter]");
    var empty = document.querySelector("[data-review-empty]");
    var prefix = phoneSearchDigits(phoneInput ? phoneInput.value : "");
    if (!prefix) prefix = phoneSearchDigits(extra ? extra.value : "");
    var textQ = extra ? extra.value.toLowerCase().trim() : "";
    var extraIsPhone = extra ? phoneSearchDigits(extra.value) !== "" : false;
    var rows = table.querySelectorAll("tbody tr[data-phone]");
    var shown = 0;
    rows.forEach(function (row) {
      var phone = row.getAttribute("data-phone") || "";
      var text = row.getAttribute("data-row-text") || "";
      var ok = true;
      if (prefix && phone.indexOf(prefix) !== 0) ok = false;
      if (textQ && !extraIsPhone && text.indexOf(textQ) === -1) ok = false;
      row.classList.toggle("hidden", !ok);
      if (ok) shown += 1;
    });
    if (empty) empty.classList.toggle("hidden", !prefix || shown > 0);
  }

  on(document.querySelector("[data-review-phone]"), "input", applyReviewPhoneFilter);
  on(document.querySelector("[data-review-filter]"), "input", applyReviewPhoneFilter);

  var loyalty = document.querySelector("[data-loyalty]");
  if (loyalty) {
    var box = document.querySelector("[data-topup-box]");
    var selected = null;
    loyalty.querySelectorAll("[data-tier]").forEach(function (btn) {
      on(btn, "click", function () {
        loyalty.querySelectorAll("[data-tier]").forEach(function (b) {
          b.classList.remove("border-primary", "bg-accent");
          b.classList.add("border-border", "bg-card");
        });
        if (selected === btn) {
          selected = null;
          if (box) box.classList.add("hidden");
          return;
        }
        selected = btn;
        btn.classList.add("border-primary", "bg-accent");
        btn.classList.remove("border-border");
        if (box) {
          box.classList.remove("hidden");
          box.classList.add("flex");
          btn.insertAdjacentElement("afterend", box);
        }
        calcCash();
      });
    });
    function calcCash() {
      var inp = document.querySelector("[data-topup]");
      var out = document.querySelector("[data-cashback]");
      if (!inp || !out || !selected) return;
      var top = Number(String(inp.value).replace(",", ".").replace(/\s/g, "")) || 0;
      var pct = Number(selected.getAttribute("data-percent") || 0);
      out.textContent = top > 0 ? Math.floor((top * pct) / 100).toLocaleString("ru-RU") + " ₽" : "—";
    }
    on(document.querySelector("[data-topup]"), "input", calcCash);
  }

  var timeCalc = document.querySelector("[data-time-calc]");
  if (timeCalc) {
    function minutes() {
      var dur = Number(String((document.querySelector("[data-duration]") || {}).value || "").replace(",", ".").replace(/\s/g, "")) || 0;
      var unit = (document.querySelector("[data-unit]") || {}).value || "minutes";
      return unit === "hours" ? Math.round(dur * 60) : Math.floor(dur);
    }
    function paint() {
      var m = minutes();
      document.querySelectorAll("[data-zone-price]").forEach(function (el) {
        var hour = Number(el.getAttribute("data-hour") || 0);
        el.textContent = m > 0 && hour > 0 ? Math.ceil((m * hour) / 60).toLocaleString("ru-RU") + " ₽" : "—";
      });
    }
    on(document.querySelector("[data-duration]"), "input", paint);
    on(document.querySelector("[data-unit]"), "change", paint);
    document.querySelectorAll("[data-branch-tab]").forEach(function (btn) {
      on(btn, "click", function () {
        var i = btn.getAttribute("data-branch-tab");
        document.querySelectorAll("[data-branch-tab]").forEach(function (b) {
          var onb = b === btn;
          b.classList.toggle("bg-primary", onb);
          b.classList.toggle("text-primary-foreground", onb);
          b.classList.toggle("text-muted-foreground", !onb);
        });
        document.querySelectorAll("[data-branch-panel]").forEach(function (p) {
          p.classList.toggle("hidden", p.getAttribute("data-branch-panel") !== i);
        });
      });
    });
  }

  var assignSel = document.querySelector("[data-assign-emp]");
  if (assignSel) {
    var ASSIGN_KEY = "gamerpro-clean-assign";
    function applyAssign(id) {
      document.querySelectorAll("[data-clean-form]").forEach(function (form) {
        var action = form.querySelector('input[name="_action"]');
        var emp = form.querySelector("[data-assign-id]");
        if (action) action.value = id ? "assign_seats" : "toggle_clean";
        if (emp) emp.value = id;
      });
    }
    try {
      var saved = sessionStorage.getItem(ASSIGN_KEY) || "";
      if (saved) {
        assignSel.value = saved;
        applyAssign(saved);
      }
    } catch (e) {}
    on(assignSel, "change", function () {
      var id = assignSel.value;
      try {
        sessionStorage.setItem(ASSIGN_KEY, id);
      } catch (e) {}
      applyAssign(id);
    });
    var doneBtn = document.querySelector("[data-assign-done]");
    on(doneBtn, "click", function () {
      assignSel.value = "";
      try {
        sessionStorage.removeItem(ASSIGN_KEY);
      } catch (e) {}
      applyAssign("");
    });
  }

  function shiftWord(n) {
    var m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return "смена";
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return "смены";
    return "смен";
  }

  function parseKey(key) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(key);
    return m ? { y: +m[1], mo: +m[2] - 1, d: +m[3] } : null;
  }

  function rangeLabel(from, to) {
    if (from > to) {
      var t = from;
      from = to;
      to = t;
    }
    var a = parseKey(from), b = parseKey(to);
    if (!a || !b) return from + " — " + to;
    if (from === to) return a.d + " " + MONTHS[a.mo];
    if (a.mo === b.mo) return a.d + "–" + b.d + " " + MONTHS[a.mo];
    return a.d + " " + MONTHS[a.mo] + " — " + b.d + " " + MONTHS[b.mo];
  }

  var grid = document.querySelector("[data-month-grid]");
  if (grid) {
    var emptyBox = document.querySelector("[data-range-empty]");
    var rangeBox = document.querySelector("[data-range-box]");
    var halfBox = document.querySelector("[data-half-box]");
    var last = Number(grid.getAttribute("data-last") || 31);
    var year = Number(grid.getAttribute("data-year"));
    var month = Number(grid.getAttribute("data-month"));
    var canEdit = grid.getAttribute("data-can-edit") === "1";
    var empFilter = "";
    var drag = null;
    var selected = null;
    var DRAG_PX = 8;

    function pad(n) {
      return n < 10 ? "0" + n : String(n);
    }
    function keyFor(day) {
      return year + "-" + pad(month + 1) + "-" + pad(day);
    }
    function inRange(key, from, to) {
      if (from > to) {
        var t = from;
        from = to;
        to = t;
      }
      return key >= from && key <= to;
    }

    function applyFilter() {
      grid.querySelectorAll("[data-emp]").forEach(function (el) {
        el.classList.toggle("hidden", empFilter !== "" && el.getAttribute("data-emp") !== empFilter);
      });
      document.querySelectorAll("[data-emp-filter]").forEach(function (btn) {
        var on = (btn.getAttribute("data-emp-filter") || "") === empFilter;
        btn.setAttribute("aria-pressed", on ? "true" : "false");
        btn.classList.toggle("shadow-md", on);
        btn.classList.toggle("text-foreground", on);
        btn.classList.toggle("text-muted-foreground", !on);
      });
      if (halfBox && !selected) updateHalf();
      if (selected) paintRange();
    }

    function updateHalf() {
      if (!halfBox) return;
      var data = {};
      try {
        data = JSON.parse(halfBox.getAttribute("data-half") || "{}");
      } catch (e) {}
      var id = empFilter || halfBox.getAttribute("data-me") || "";
      var row = data[id];
      if (!row) {
        halfBox.hidden = true;
        return;
      }
      halfBox.hidden = false;
      var title = halfBox.querySelector("[data-half-title]");
      if (title) title.textContent = empFilter ? row.name : "Ваши смены";
      function set(sel, n) {
        var el = halfBox.querySelector(sel);
        if (el) el.innerHTML = n + ' <span class="text-muted-foreground text-xs font-normal">' + shiftWord(n) + "</span>";
      }
      set("[data-half-first]", row.first);
      set("[data-half-second]", row.second);
      set("[data-half-month]", row.month);
    }

    function paintRange() {
      var cells = grid.querySelectorAll("[data-date-key]");
      cells.forEach(function (cell) {
        var key = cell.getAttribute("data-date-key");
        var onr = selected && inRange(key, selected.from, selected.to);
        cell.classList.toggle("border-primary", !!onr);
        cell.classList.toggle("bg-primary/15", !!onr);
        cell.classList.toggle("ring-2", !!onr);
        cell.classList.toggle("ring-primary/35", !!onr);
      });
      if (!selected) {
        if (emptyBox) emptyBox.classList.remove("hidden");
        if (rangeBox) rangeBox.classList.add("hidden");
        if (halfBox) halfBox.hidden = false;
        return;
      }
      if (emptyBox) emptyBox.classList.add("hidden");
      if (rangeBox) rangeBox.classList.remove("hidden");
      if (halfBox) halfBox.hidden = true;
      var from = selected.from <= selected.to ? selected.from : selected.to;
      var to = selected.from <= selected.to ? selected.to : selected.from;
      var totals = {};
      var names = {};
      cells.forEach(function (cell) {
        var key = cell.getAttribute("data-date-key");
        if (!inRange(key, from, to)) return;
        cell.querySelectorAll("[data-emp]").forEach(function (el) {
          if (el.classList.contains("hidden")) return;
          var id = el.getAttribute("data-emp");
          var w = Number(el.getAttribute("data-weight") || 1);
          totals[id] = (totals[id] || 0) + w;
          names[id] = el.getAttribute("data-emp-name") || "#" + id;
        });
      });
      var rows = Object.keys(totals)
        .map(function (id) {
          return { id: id, name: names[id], count: totals[id] };
        })
        .sort(function (a, b) {
          return b.count - a.count;
        });
      var total = rows.reduce(function (s, r) {
        return s + r.count;
      }, 0);
      var html =
        '<div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Выделено</p><p class="text-base font-semibold">' +
        rangeLabel(from, to) +
        '</p></div><div class="flex flex-wrap gap-2"><button type="button" class="group/button inline-flex h-7 items-center rounded-lg border border-border bg-background px-2.5 text-[0.8rem] font-medium" data-range-preset="1-15">1–15</button><button type="button" class="group/button inline-flex h-7 items-center rounded-lg border border-border bg-background px-2.5 text-[0.8rem] font-medium" data-range-preset="16-end">16–' +
        last +
        '</button><button type="button" class="group/button inline-flex h-7 items-center rounded-lg px-2.5 text-[0.8rem] font-medium hover:bg-muted" data-range-clear>Сбросить</button></div></div>';
      html +=
        '<p class="text-primary mt-3 text-4xl font-bold tabular-nums sm:text-5xl">' +
        total +
        '<span class="text-muted-foreground ml-2 text-lg font-normal sm:text-xl">' +
        shiftWord(total) +
        "</span></p>";
      html +=
        '<p class="text-muted-foreground mt-1 text-xs">сутки = 2 · ' +
        (empFilter ? "только выбранный сотрудник" : "по всем сотрудникам в выделении") +
        "</p>";
      if (rows.length && !empFilter) {
        html += '<ul class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">';
        rows.forEach(function (r) {
          html +=
            '<li class="bg-muted/50 flex items-center justify-between gap-2 rounded-lg px-3 py-2"><span class="min-w-0 text-sm font-medium" style="overflow-wrap:anywhere">' +
            r.name +
            '</span><span class="text-lg font-semibold tabular-nums">' +
            r.count +
            '<span class="text-muted-foreground ml-1 text-xs font-normal">' +
            shiftWord(r.count) +
            "</span></span></li>";
        });
        html += "</ul>";
      } else if (total === 0) {
        html += '<p class="text-muted-foreground mt-4 text-sm">В этом периоде смен нет.</p>';
      }
      rangeBox.innerHTML = html;
    }

    function setRange(from, to) {
      selected = { from: from, to: to };
      paintRange();
    }

    function openDay(cell) {
      var dlg = document.querySelector("[data-day-dialog]");
      if (!dlg || !dlg.showModal) return;
      var key = cell.getAttribute("data-date-key");
      var title = dlg.querySelector("[data-dialog-title]");
      var dateInp = dlg.querySelector("[data-dialog-date]");
      var existing = dlg.querySelector("[data-existing]");
      var typeLab = { day: "День · 08:00–20:00", night: "Ночь · 20:00–08:00", full: "Сутки · 24 часа" };
      if (title) {
        var bits = (key || "").split("-");
        var dayN = parseInt(bits[2], 10);
        var mon = parseInt(bits[1], 10);
        title.textContent = dayN && mon ? "Смена · " + dayN + " " + MONTHS[mon - 1] : "Смена · " + key;
      }
      if (dateInp) dateInp.value = key;
      if (existing) {
        existing.innerHTML = "";
        cell.querySelectorAll("[data-emp]").forEach(function (el) {
          var name = el.getAttribute("data-emp-name") || "";
          var type = el.getAttribute("data-type") || "";
          var away = el.getAttribute("data-away");
          var lab = away ? "в другом клубе" : typeLab[type] || type;
          if (canEdit && !away) {
            var form = document.createElement("form");
            form.method = "post";
            form.className = "flex flex-col gap-2 rounded-lg border border-border px-3 py-2 text-sm sm:flex-row sm:items-center sm:justify-between";
            form.innerHTML =
              '<input type="hidden" name="_csrf" value="' +
              ((window.GP && window.GP.csrf) || "") +
              '"><input type="hidden" name="_action" value="delete_shift"><input type="hidden" name="id" value="' +
              el.getAttribute("data-shift-id") +
              '"><span class="min-w-0 whitespace-normal break-words"></span><button class="text-destructive shrink-0 self-start text-sm">Убрать</button>';
            var span = form.querySelector("span");
            if (span) span.textContent = name + (lab ? " · " + lab : "");
            existing.appendChild(form);
          } else {
            var row = document.createElement("div");
            row.className = "flex flex-col gap-1 rounded-lg border border-border px-3 py-2 text-sm sm:flex-row sm:items-center sm:justify-between sm:gap-2";
            var who = document.createElement("span");
            who.className = "min-w-0 whitespace-normal break-words";
            who.textContent = name;
            var kind = document.createElement("span");
            kind.className = "text-muted-foreground text-xs";
            kind.textContent = lab;
            row.appendChild(who);
            row.appendChild(kind);
            existing.appendChild(row);
          }
        });
        if (!existing.children.length) {
          var empty = document.createElement("p");
          empty.className = "text-muted-foreground text-sm";
          empty.textContent = "В этот день смен нет.";
          existing.appendChild(empty);
        }
      }
      dlg.showModal();
    }

    var finePointer = window.matchMedia && window.matchMedia("(pointer: fine)").matches;
    grid.querySelectorAll("[data-date-key]").forEach(function (cell) {
      on(cell, "keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          openDay(cell);
        }
      });
      if (!finePointer) {
        on(cell, "click", function () {
          openDay(cell);
        });
        return;
      }
      on(cell, "mousedown", function (e) {
        if (e.button !== 0) return;
        e.preventDefault();
        drag = {
          anchor: cell.getAttribute("data-date-key"),
          cell: cell,
          x: e.clientX,
          y: e.clientY,
          moved: false,
        };
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
          selected = null;
          paintRange();
          openDay(drag.cell);
        }
        drag = null;
      });
    }

    document.querySelectorAll("[data-emp-filter]").forEach(function (btn) {
      on(btn, "click", function () {
        empFilter = btn.getAttribute("data-emp-filter") || "";
        applyFilter();
      });
    });

    on(document, "click", function (e) {
      var preset = e.target.closest("[data-range-preset]");
      var clear = e.target.closest("[data-range-clear]");
      if (preset) {
        var kind = preset.getAttribute("data-range-preset");
        setRange(keyFor(kind === "1-15" ? 1 : 16), keyFor(kind === "1-15" ? 15 : last));
      }
      if (clear) {
        selected = null;
        paintRange();
      }
    });

    document.querySelectorAll("[data-dialog-close]").forEach(function (b) {
      on(b, "click", function () {
        var dlg = document.querySelector("[data-day-dialog]");
        if (dlg && dlg.close) dlg.close();
      });
    });
  }

  var flash = document.querySelector("[role=status]");
  if (flash) {
    setTimeout(function () {
      flash.remove();
    }, 4200);
  }

  (function examEditor() {
    var root = document.querySelector("[data-exam-editor]");
    if (!root) return;
    var list = root.querySelector("[data-questions]");
    var tpl = root.querySelector("[data-q-tpl]");
    var addBtn = root.querySelector("[data-add-question]");
    var countEl = root.querySelector("[data-q-count]");
    if (!list || !tpl) return;

    function kindOf(q) {
      var sel = q.querySelector("[data-kind]");
      return sel ? sel.value : "single";
    }

    function reindex() {
      var questions = list.querySelectorAll("[data-question]");
      if (countEl) countEl.textContent = String(questions.length);
      questions.forEach(function (q, i) {
        var num = q.querySelector("[data-q-num]");
        if (num) num.textContent = i + 1 + ".";
        var prompt = q.querySelector("[data-prompt]");
        if (prompt) prompt.name = "questions[" + i + "][prompt]";
        var kindSel = q.querySelector("[data-kind]");
        if (kindSel) kindSel.name = "questions[" + i + "][kind]";
        var expl = q.querySelector("[data-expl]");
        if (expl) expl.name = "questions[" + i + "][explanation]";
        var kind = kindOf(q);
        var options = q.querySelectorAll("[data-option]");
        var addOpt = q.querySelector("[data-opt-add]");
        if (addOpt) addOpt.classList.toggle("hidden", kind === "boolean");
        options.forEach(function (opt, j) {
          var label = opt.querySelector("[data-opt-label]");
          if (label) {
            label.name = "questions[" + i + "][options][" + j + "][label]";
            label.placeholder = "Вариант " + (j + 1);
            label.readOnly = kind === "boolean";
          }
          var mark = opt.querySelector("[data-correct]");
          if (mark) {
            if (kind === "multiple") {
              mark.type = "checkbox";
              mark.name = "questions[" + i + "][options][" + j + "][correct]";
              mark.value = "1";
            } else {
              mark.type = "radio";
              mark.name = "questions[" + i + "][correct]";
              mark.value = String(j);
            }
          }
          var del = opt.querySelector("[data-opt-remove]");
          if (del) del.classList.toggle("hidden", kind === "boolean" || options.length <= 2);
        });
      });
    }

    function applyKind(q, kind) {
      var box = q.querySelector("[data-options]");
      if (!box) return;
      if (kind === "boolean") {
        var opts = box.querySelectorAll("[data-option]");
        while (opts.length < 2) {
          box.appendChild(makeOption());
          opts = box.querySelectorAll("[data-option]");
        }
        while (opts.length > 2) {
          box.removeChild(opts[opts.length - 1]);
          opts = box.querySelectorAll("[data-option]");
        }
        var labels = ["Да", "Нет"];
        opts.forEach(function (opt, j) {
          var input = opt.querySelector("[data-opt-label]");
          if (input) input.value = labels[j];
        });
      }
      reindex();
    }

    function makeOption() {
      var first = list.querySelector("[data-option]");
      var node;
      if (first) {
        node = first.cloneNode(true);
      } else {
        var tmp = document.createElement("div");
        tmp.innerHTML = tpl.innerHTML;
        first = tmp.querySelector("[data-option]");
        node = first.cloneNode(true);
      }
      var input = node.querySelector("[data-opt-label]");
      if (input) {
        input.value = "";
        input.readOnly = false;
      }
      var mark = node.querySelector("[data-correct]");
      if (mark) mark.checked = false;
      return node;
    }

    if (addBtn) {
      addBtn.addEventListener("click", function () {
        var node = tpl.content.firstElementChild.cloneNode(true);
        list.appendChild(node);
        applyKind(node, "single");
        var ta = node.querySelector("[data-prompt]");
        if (ta) ta.focus();
      });
    }

    root.addEventListener("click", function (e) {
      var addOpt = e.target.closest("[data-opt-add]");
      if (addOpt) {
        var q = addOpt.closest("[data-question]");
        if (!q || kindOf(q) === "boolean") return;
        var box = q.querySelector("[data-options]");
        if (box) box.appendChild(makeOption());
        reindex();
        return;
      }
      var delOpt = e.target.closest("[data-opt-remove]");
      if (delOpt) {
        var opt = delOpt.closest("[data-option]");
        var q = delOpt.closest("[data-question]");
        if (!opt || !q) return;
        var box = q.querySelector("[data-options]");
        if (box && box.querySelectorAll("[data-option]").length > 2) opt.remove();
        reindex();
        return;
      }
      var delQ = e.target.closest("[data-q-remove]");
      if (delQ) {
        var qn = delQ.closest("[data-question]");
        if (qn) qn.remove();
        reindex();
      }
    });

    root.addEventListener("change", function (e) {
      var kindSel = e.target.closest("[data-kind]");
      if (!kindSel) return;
      var q = kindSel.closest("[data-question]");
      if (!q) return;
      if (kindSel.value === "single" || kindSel.value === "boolean") {
        var marks = q.querySelectorAll("[data-correct]");
        var kept = false;
        marks.forEach(function (m) {
          if (m.checked && kept) m.checked = false;
          if (m.checked) kept = true;
        });
      }
      applyKind(q, kindSel.value);
    });

    reindex();
  })();

  (function examTake() {
    var root = document.querySelector("[data-exam-take]");
    if (!root) return;
    var steps = Array.prototype.slice.call(root.querySelectorAll("[data-exam-step]"));
    if (!steps.length) return;
    var numEl = root.querySelector("[data-exam-num]");
    var prevBtn = root.querySelector("[data-exam-prev]");
    var nextBtn = root.querySelector("[data-exam-next]");
    var submitBtn = root.querySelector("[data-exam-submit]");
    var exit = root.getAttribute("data-exam-exit") || "/exams";
    var i = 0;

    function pad(n) {
      return (n < 10 ? "0" : "") + n;
    }

    function show(idx) {
      i = Math.max(0, Math.min(idx, steps.length - 1));
      steps.forEach(function (step, j) {
        step.classList.toggle("is-on", j === i);
      });
      if (numEl) numEl.textContent = pad(i + 1);
      var last = i === steps.length - 1;
      if (nextBtn) nextBtn.classList.toggle("hidden", last);
      if (submitBtn) submitBtn.classList.toggle("hidden", !last);
    }

    root.classList.add("is-stepping");
    if (prevBtn) {
      prevBtn.addEventListener("click", function () {
        if (i === 0) {
          window.location.href = exit;
          return;
        }
        show(i - 1);
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener("click", function () {
        show(i + 1);
      });
    }
    show(0);
  })();

  (function newsModal() {
    var dlg = document.querySelector("[data-news-dialog]");
    if (!dlg || !dlg.showModal) return;
    var titleEl = dlg.querySelector("[data-news-dlg-title]");
    var metaEl = dlg.querySelector("[data-news-dlg-meta]");
    var bodyEl = dlg.querySelector("[data-news-dlg-body]");
    var pinEl = dlg.querySelector("[data-news-pin]");
    var readForm = dlg.querySelector("[data-news-read-form]");
    var delForm = dlg.querySelector("[data-news-del-form]");

    function closeDlg() {
      if (dlg.close) dlg.close();
    }

    function fillFromCard(card) {
      if (!card) return;
      var full = card.querySelector("[data-news-full]");
      var titleNode = card.querySelector("[data-news-title]");
      var metaNode = card.querySelector("[data-news-meta]");
      if (titleEl) titleEl.textContent = titleNode ? titleNode.textContent : "";
      if (metaEl) metaEl.textContent = metaNode ? metaNode.textContent : "";
      if (bodyEl) bodyEl.textContent = full && full.content ? full.content.textContent : "";
      if (pinEl) pinEl.classList.toggle("hidden", card.getAttribute("data-pinned") !== "1");
      var id = card.getAttribute("data-id") || "";
      var unread = card.getAttribute("data-unread") === "1";
      if (readForm) {
        readForm.classList.toggle("hidden", !unread);
        var idInp = readForm.querySelector("[data-news-id-field]");
        if (idInp) idInp.value = id;
      }
      if (delForm) {
        var idInp2 = delForm.querySelector("[data-news-id-field]");
        if (idInp2) idInp2.value = id;
      }
      dlg.showModal();
    }

    on(document, "click", function (e) {
      var closeBtn = e.target.closest("[data-news-close]");
      if (closeBtn && dlg.contains(closeBtn)) {
        closeDlg();
        return;
      }
      var btn = e.target.closest("[data-news-open]");
      if (!btn) return;
      fillFromCard(btn.closest("[data-news-card]"));
    });
  })();

  (function finesPager() {
    document.querySelectorAll("[data-fines-list]").forEach(function (box) {
      var rows = Array.prototype.slice.call(box.querySelectorAll("[data-fine-row]"));
      var btn = box.querySelector("[data-fines-more]");
      if (!btn || !rows.length) return;
      var step = parseInt(box.getAttribute("data-fines-step") || "10", 10) || 10;
      var shown = rows.filter(function (row) {
        return !row.classList.contains("hidden");
      }).length;
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        var next = Math.min(shown + step, rows.length);
        rows.forEach(function (row, i) {
          row.classList.toggle("hidden", i >= next);
        });
        shown = next;
        var left = rows.length - shown;
        if (left <= 0) {
          btn.remove();
          return;
        }
        btn.textContent = "Ещё " + Math.min(step, left);
      });
    });
  })();
})();
