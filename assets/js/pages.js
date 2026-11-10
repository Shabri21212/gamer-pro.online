/*
 * GAMER PRO CRM — обработчики отдельных страниц: бонусная система, отзывы, задания, штрафы,
 * тарифы, база знаний, доступы, экзамены, приём и сдача смены, чистка клавиатур.
 * Общие вещи (окна, формы, уведомления) — в app.js.
 */
(function () {
  "use strict";

  var GP = window.GP || {};
  var on = GP.on;
  var all = GP.all;
  var one = GP.one;
  if (!on) return;

  function readJson(sel, root) {
    var node = one(sel, root);
    if (!node) return {};
    try {
      return JSON.parse(node.textContent || "{}") || {};
    } catch (err) {
      return {};
    }
  }

  /* ---------- бонусная система: уровни и кэшбек ---------- */

  (function loyalty() {
    var box = one("[data-tiers]");
    if (!box) return;
    var tiers = all("[data-tier]", box);
    var topup = one("[data-topup]");
    var out = one("[data-cashback]");
    var nameEl = one("[data-tier-name]");
    var percentEl = one("[data-tier-percent]");
    var current = tiers.filter(function (t) {
      return t.getAttribute("aria-checked") === "true";
    })[0] || tiers[0];
    if (!current) return;

    function cashback(tier, sum) {
      return Math.floor((sum * Number(tier.getAttribute("data-percent") || 0)) / 100);
    }
    function paint() {
      var sum = topup ? GP.num(topup.value) : 0;
      tiers.forEach(function (tier) {
        var active = tier === current;
        tier.setAttribute("aria-checked", active ? "true" : "false");
        tier.tabIndex = active ? 0 : -1;
        var cash = one("[data-tier-cash]", tier);
        if (cash) {
          cash.hidden = !(sum > 0);
          cash.textContent = "+" + GP.money(cashback(tier, sum));
        }
      });
      if (nameEl) nameEl.textContent = current.getAttribute("data-title") || "";
      if (percentEl) percentEl.textContent = current.getAttribute("data-percent") || "0";
      if (out) out.textContent = sum > 0 ? GP.money(cashback(current, sum)) : "—";
    }
    function select(tier, focus) {
      current = tier;
      if (focus) tier.focus();
      paint();
    }
    tiers.forEach(function (tier, i) {
      on(tier, "click", function () {
        select(tier, false);
      });
      on(tier, "keydown", function (e) {
        var step = e.key === "ArrowDown" || e.key === "ArrowRight" ? 1 : e.key === "ArrowUp" || e.key === "ArrowLeft" ? -1 : 0;
        if (!step) return;
        e.preventDefault();
        select(tiers[(i + step + tiers.length) % tiers.length], true);
      });
    });
    on(topup, "input", paint);
    all("[data-topup-preset]").forEach(function (btn) {
      on(btn, "click", function () {
        if (!topup) return;
        topup.value = btn.getAttribute("data-topup-preset") || "";
        paint();
      });
    });
    paint();
  })();

  /* ---------- стоимость времени ---------- */

  (function timeCalc() {
    var root = one("[data-time-calc]");
    if (!root) return;
    var duration = one("[data-duration]", root);
    var unit = one("[data-unit]", root);
    function paint() {
      var value = duration ? GP.num(duration.value) : 0;
      var minutes = unit && unit.value === "hours" ? Math.round(value * 60) : Math.floor(value);
      all("[data-zone-price]", root).forEach(function (el) {
        var hour = Number(el.getAttribute("data-hour") || 0);
        el.textContent = minutes > 0 && hour > 0 ? GP.money(Math.ceil((minutes * hour) / 60)) : "—";
      });
    }
    on(duration, "input", paint);
    on(unit, "change", paint);
    all("[data-branch-tab]", root).forEach(function (tab) {
      on(tab, "click", function () {
        var index = tab.getAttribute("data-branch-tab");
        all("[data-branch-tab]", root).forEach(function (t) {
          t.setAttribute("aria-selected", t === tab ? "true" : "false");
        });
        all("[data-branch-panel]", root).forEach(function (panel) {
          panel.classList.toggle("hidden", panel.getAttribute("data-branch-panel") !== index);
        });
      });
    });
  })();

  /* ---------- бонусы за отзывы: номер проверяется, пока его вводят ---------- */

  (function reviewPhone() {
    var form = one("[data-review-form]");
    if (!form) return;
    var branch = one("[data-review-branch]", form);
    var phone = one("[data-review-phone]", form);
    var hint = one("[data-phone-hint]", form);
    var known = readJson("[data-review-known]", form);
    if (!phone || !hint) return;
    var idle = hint.textContent;
    function check() {
      var digits = phone.value.replace(/\D/g, "");
      if (digits.length === 11 && (digits.charAt(0) === "7" || digits.charAt(0) === "8")) digits = digits.slice(1);
      var before = digits.length === 10 ? (known[branch ? branch.value : ""] || {})[digits] : null;
      hint.textContent = before ? "Этот номер уже получал бонус на этом филиале: " + before + "." : digits.length === 10 ? "На этом филиале номер бонус ещё не получал." : idle;
      hint.classList.toggle("text-primary-ink", !!before);
      hint.classList.toggle("font-semibold", !!before);
      hint.classList.toggle("text-muted-foreground", !before);
    }
    on(phone, "input", check);
    on(branch, "change", check);
  })();

  /* ---------- задания на смены ---------- */

  (function tasks() {
    var form = document.getElementById("assign");
    if (!form || !one("[data-who]", form)) return;
    var picked = one("[data-who-picked]", form);
    var title = one("[data-task-title]", form);
    var desc = one("[data-task-desc]", form);
    function syncWho() {
      var chosen = one("[data-who]:checked", form);
      var off = !chosen || chosen.value !== "picked";
      if (!picked) return;
      picked.classList.toggle("opacity-50", off);
      all("input", picked).forEach(function (input) {
        input.disabled = off;
      });
    }
    all("[data-who]", form).forEach(function (radio) {
      on(radio, "change", syncWho);
    });
    syncWho();

    var templates = readJson("[data-task-templates]", form);
    var templateSel = one("[data-task-template]", form);
    on(templateSel, "change", function () {
      var tpl = templates[templateSel.value];
      if (!tpl) return;
      if (title) title.value = tpl.title || "";
      if (desc) desc.value = tpl.description || "";
    });

    // «+» в ячейке недели заполняет форму без перезагрузки страницы.
    on(document, "click", function (e) {
      var link = e.target.closest("[data-fill-task]");
      if (!link) return;
      e.preventDefault();
      var who = one('[data-who][value="picked"]', form);
      if (who) who.checked = true;
      syncWho();
      var emp = link.getAttribute("data-emp");
      all('input[name="employees[]"]', form).forEach(function (box) {
        box.checked = box.value === emp;
      });
      ["from", "to"].forEach(function (name) {
        var input = form.elements[name];
        if (input) input.value = link.getAttribute("data-date") || "";
      });
      form.scrollIntoView({ block: "start", behavior: "smooth" });
      if (title) title.focus({ preventScroll: true });
    });
  })();

  /* ---------- назначение штрафа ---------- */

  (function fines() {
    var form = one("[data-fine-form]");
    if (!form) return;
    var rules = readJson("[data-fine-rules]", form);
    var ruleSel = one("[data-fine-rule]", form);
    var box = one("[data-fine-repeat-box]", form);
    var reason = one("[data-fine-reason]", form);
    var amount = one("[data-fine-amount]", form);
    function apply(fill) {
      var rule = ruleSel ? rules[ruleSel.value] : null;
      if (box) {
        box.classList.toggle("hidden", !rule);
        box.classList.toggle("flex", !!rule);
      }
      if (!rule || !fill) return;
      var repeat = one("[data-fine-repeat]:checked", form);
      var sum = repeat && repeat.value === "1" ? rule.repeat : rule.first;
      if (fill === "rule" && reason) reason.value = rule.violation || "";
      // Сумма null — по этому пункту увольнение, сумму управляющий вписывает сам.
      if (amount) amount.value = sum == null ? "" : sum;
    }
    on(ruleSel, "change", function () {
      apply("rule");
    });
    all("[data-fine-repeat]", form).forEach(function (radio) {
      on(radio, "change", function () {
        apply("amount");
      });
    });
    apply("");
  })();

  /* ---------- редактор тарифов: строки пакетов ---------- */

  on(document, "click", function (e) {
    var add = e.target.closest("[data-price-add]");
    if (add) {
      var body = one("[data-price-rows]", add.parentElement);
      var rows = body ? all("[data-price-row]", body) : [];
      if (!rows.length) return;
      var next = 0;
      rows.forEach(function (row) {
        var m = /\[packages\]\[(\d+)\]/.exec((one("input", row) || {}).name || "");
        if (m) next = Math.max(next, Number(m[1]) + 1);
      });
      var clone = rows[rows.length - 1].cloneNode(true);
      all("input", clone).forEach(function (input) {
        input.value = "";
        input.name = input.name.replace(/\[packages\]\[\d+\]/, "[packages][" + next + "]");
      });
      body.appendChild(clone);
      one("input", clone).focus();
      return;
    }
    var remove = e.target.closest("[data-price-remove]");
    if (!remove) return;
    var row = remove.closest("[data-price-row]");
    if (!row) return;
    if (all("[data-price-row]", row.parentElement).length > 1) {
      row.remove();
    } else {
      all("input", row).forEach(function (input) {
        input.value = "";
      });
    }
  });

  /* ---------- база знаний: поиск, раздел, редактор ---------- */

  (function wikiSearch() {
    var nav = one("[data-wiki-nav]");
    var input = nav ? one("[data-wiki-search]", nav) : null;
    if (!input) return;
    var empty = one("[data-wiki-empty]", nav);
    on(input, "input", function () {
      var q = input.value.toLowerCase().trim();
      var shown = 0;
      all("[data-wiki-group]", nav).forEach(function (group) {
        var visible = 0;
        all("[data-wiki-item]", group).forEach(function (item) {
          var ok = q === "" || (item.getAttribute("data-text") || "").indexOf(q) !== -1;
          item.hidden = !ok;
          if (ok) visible++;
        });
        group.hidden = visible === 0;
        shown += visible;
      });
      if (empty) empty.classList.toggle("hidden", shown > 0);
    });
  })();

  (function wikiCategory() {
    var select = one("[data-category-select]");
    var box = one("[data-new-category]");
    if (!select || !box) return;
    function sync(focus) {
      var isNew = select.value === "new";
      box.classList.toggle("hidden", !isNew);
      var input = one("input", box);
      if (input) {
        input.required = isNew;
        if (isNew && focus) input.focus();
      }
    }
    on(select, "change", function () {
      sync(true);
    });
    sync(false);
  })();

  // Чистит вставленный вручную HTML перед показом в редакторе; на сервере статью повторно чистит sanitize_html().
  function scrubHtml(html) {
    var doc = new DOMParser().parseFromString("<div>" + html + "</div>", "text/html");
    all("script, style, iframe, object, embed, link, meta, form", doc).forEach(function (node) {
      node.remove();
    });
    all("*", doc).forEach(function (node) {
      Array.prototype.slice.call(node.attributes).forEach(function (attr) {
        if (/^on/i.test(attr.name) || (/^(href|src)$/i.test(attr.name) && /^\s*(javascript|data|vbscript):/i.test(attr.value))) node.removeAttribute(attr.name);
      });
    });
    return doc.body.firstChild ? doc.body.firstChild.innerHTML : "";
  }

  all("[data-editor]").forEach(function (editor) {
    var area = one("[data-editor-area]", editor);
    var input = one("[data-editor-input]", editor);
    var sourceBtn = one("[data-editor-source]", editor);
    var form = editor.closest("form");
    var source = false;
    if (!area || !input) return;

    function exec(cmd, value) {
      area.focus();
      document.execCommand(cmd, false, value);
    }
    function blockTag() {
      var sel = window.getSelection();
      var node = sel && sel.anchorNode;
      while (node && node !== area) {
        if (node.nodeType === 1 && /^(H2|H3|H4|BLOCKQUOTE|P|LI)$/.test(node.tagName)) return node.tagName;
        node = node.parentNode;
      }
      return "";
    }
    var commands = {
      bold: function () {
        exec("bold");
      },
      italic: function () {
        exec("italic");
      },
      h2: function () {
        exec("formatBlock", blockTag() === "H2" ? "p" : "h2");
      },
      ul: function () {
        exec("insertUnorderedList");
      },
      ol: function () {
        exec("insertOrderedList");
      },
      quote: function () {
        exec("formatBlock", blockTag() === "BLOCKQUOTE" ? "p" : "blockquote");
      },
      link: function () {
        var url = (window.prompt("Адрес ссылки", "https://") || "").trim();
        if (!url || url === "https://") return;
        if (!/^(https?:\/\/|mailto:|tel:|\/)/i.test(url)) url = "https://" + url;
        exec("createLink", url);
      },
      table: function () {
        var cells = "<td><br></td><td><br></td><td><br></td>";
        exec("insertHTML", "<table><thead><tr><th>Заголовок</th><th>Заголовок</th><th>Заголовок</th></tr></thead><tbody><tr>" + cells + "</tr><tr>" + cells + "</tr></tbody></table><p><br></p>");
      },
      clear: function () {
        exec("removeFormat");
        exec("formatBlock", "p");
      },
    };

    // Нажатие на кнопку панели не должно снимать выделение в тексте.
    on(editor, "mousedown", function (e) {
      if (e.target.closest("[data-cmd]")) e.preventDefault();
    });
    on(editor, "click", function (e) {
      var btn = e.target.closest("[data-cmd]");
      if (!btn || source) return;
      var run = commands[btn.getAttribute("data-cmd")];
      if (run) run();
    });
    on(sourceBtn, "click", function () {
      source = !source;
      if (source) input.value = area.innerHTML;
      else area.innerHTML = scrubHtml(input.value);
      area.hidden = source;
      input.classList.toggle("hidden", !source);
      sourceBtn.setAttribute("aria-pressed", source ? "true" : "false");
      all("[data-cmd]", editor).forEach(function (btn) {
        btn.disabled = source;
      });
      (source ? input : area).focus();
    });
    on(form, "submit", function () {
      if (!source) input.value = area.innerHTML;
    });
  });

  /* ---------- доступы и права ---------- */

  all("[data-access-form]").forEach(function (form) {
    var presets = {};
    try {
      presets = JSON.parse(form.getAttribute("data-presets") || "{}") || {};
    } catch (err) {}
    var perms = one("[data-access-perms]", form);
    var scope = one("[data-access-scope]", form);
    all("[data-role-radio]", form).forEach(function (radio) {
      on(radio, "change", function () {
        if (!radio.checked) return;
        var owner = radio.value === "owner";
        var preset = presets[radio.value] || [];
        if (perms) {
          perms.disabled = false;
          all('input[type="checkbox"]', perms).forEach(function (box) {
            box.checked = preset.indexOf(box.value) !== -1;
          });
          perms.disabled = owner;
        }
        if (scope) {
          if (owner) scope.value = "";
          scope.disabled = owner;
        }
      });
    });
  });

  (function newAccount() {
    var form = one("[data-new-account]");
    if (!form) return;
    var select = one("[data-acc-employee]", form);
    var fields = one("[data-acc-new-fields]", form);
    if (!select || !fields) return;
    function sync() {
      fields.hidden = select.value !== "";
    }
    on(select, "change", sync);
    sync();
  })();

  /* ---------- экзамены: редактор вопросов ---------- */

  (function examEditor() {
    var root = one("[data-exam-editor]");
    if (!root) return;
    var list = one("[data-questions]", root);
    var tpl = one("[data-q-tpl]", root);
    var countEl = one("[data-q-count]", root);
    if (!list || !tpl) return;

    function kindOf(q) {
      var sel = one("[data-kind]", q);
      return sel ? sel.value : "single";
    }
    function reindex() {
      var questions = all("[data-question]", list);
      if (countEl) countEl.textContent = String(questions.length);
      questions.forEach(function (q, i) {
        var kind = kindOf(q);
        var options = all("[data-option]", q);
        var num = one("[data-q-num]", q);
        var prompt = one("[data-prompt]", q);
        var kindSel = one("[data-kind]", q);
        var expl = one("[data-expl]", q);
        var addOpt = one("[data-opt-add]", q);
        if (num) num.textContent = i + 1 + ".";
        if (prompt) prompt.name = "questions[" + i + "][prompt]";
        if (kindSel) kindSel.name = "questions[" + i + "][kind]";
        if (expl) expl.name = "questions[" + i + "][explanation]";
        if (addOpt) addOpt.classList.toggle("hidden", kind === "boolean");
        options.forEach(function (opt, j) {
          var label = one("[data-opt-label]", opt);
          var mark = one("[data-correct]", opt);
          var del = one("[data-opt-remove]", opt);
          if (label) {
            label.name = "questions[" + i + "][options][" + j + "][label]";
            label.placeholder = "Вариант " + (j + 1);
            label.readOnly = kind === "boolean";
          }
          if (mark) {
            mark.type = kind === "multiple" ? "checkbox" : "radio";
            mark.name = kind === "multiple" ? "questions[" + i + "][options][" + j + "][correct]" : "questions[" + i + "][correct]";
            mark.value = kind === "multiple" ? "1" : String(j);
          }
          if (del) del.classList.toggle("hidden", kind === "boolean" || options.length <= 2);
        });
      });
    }
    function makeOption() {
      var sample = one("[data-option]", list) || one("[data-option]", tpl.content);
      var node = sample.cloneNode(true);
      var label = one("[data-opt-label]", node);
      var mark = one("[data-correct]", node);
      if (label) {
        label.value = "";
        label.readOnly = false;
      }
      if (mark) mark.checked = false;
      return node;
    }
    function applyKind(q, kind) {
      var box = one("[data-options]", q);
      if (box && kind === "boolean") {
        while (all("[data-option]", box).length < 2) box.appendChild(makeOption());
        all("[data-option]", box).slice(2).forEach(function (opt) {
          opt.remove();
        });
        all("[data-option]", box).forEach(function (opt, j) {
          var label = one("[data-opt-label]", opt);
          if (label) label.value = ["Да", "Нет"][j];
        });
      }
      reindex();
    }

    on(one("[data-add-question]", root), "click", function () {
      var node = tpl.content.firstElementChild.cloneNode(true);
      list.appendChild(node);
      applyKind(node, "single");
      var prompt = one("[data-prompt]", node);
      if (prompt) prompt.focus();
    });
    on(root, "click", function (e) {
      var q = e.target.closest("[data-question]");
      if (!q) return;
      if (e.target.closest("[data-opt-add]")) {
        var box = one("[data-options]", q);
        if (box && kindOf(q) !== "boolean") box.appendChild(makeOption());
      } else if (e.target.closest("[data-opt-remove]")) {
        var opt = e.target.closest("[data-option]");
        if (opt && all("[data-option]", q).length > 2) opt.remove();
      } else if (e.target.closest("[data-q-remove]")) {
        q.remove();
      } else {
        return;
      }
      reindex();
    });
    on(root, "change", function (e) {
      var kindSel = e.target.closest("[data-kind]");
      var q = kindSel ? kindSel.closest("[data-question]") : null;
      if (!q) return;
      if (kindSel.value !== "multiple") {
        // В вопросе с одним ответом оставляем отмеченным только первый из выбранных вариантов.
        var kept = false;
        all("[data-correct]", q).forEach(function (mark) {
          if (mark.checked && kept) mark.checked = false;
          if (mark.checked) kept = true;
        });
      }
      applyKind(q, kindSel.value);
    });
    reindex();
  })();

  /* ---------- экзамены: прохождение по одному вопросу ---------- */

  (function examTake() {
    var root = one("[data-exam-take]");
    var steps = root ? all("[data-exam-step]", root) : [];
    if (!steps.length) return;
    var counter = one("[data-exam-counter]", root);
    var bar = one(".exam-progress", root);
    var prev = one("[data-exam-prev]", root);
    var next = one("[data-exam-next]", root);
    var submit = one("[data-exam-submit]", root);
    var index = 0;

    function show(target, focus) {
      index = Math.max(0, Math.min(target, steps.length - 1));
      var isLast = index === steps.length - 1;
      var percent = Math.round(((index + 1) / steps.length) * 100);
      steps.forEach(function (step, j) {
        step.classList.toggle("is-on", j === index);
      });
      if (counter) counter.textContent = "Вопрос " + (index + 1) + " из " + steps.length;
      if (bar) {
        bar.setAttribute("aria-valuenow", String(percent));
        if (bar.firstElementChild) bar.firstElementChild.style.width = percent + "%";
      }
      if (prev) prev.disabled = index === 0;
      if (next) next.classList.toggle("hidden", isLast);
      if (submit) submit.classList.toggle("hidden", !isLast);
      if (focus) {
        var input = one("input", steps[index]);
        if (input) input.focus();
      }
    }
    root.classList.add("is-stepping");
    on(prev, "click", function () {
      show(index - 1, true);
    });
    on(next, "click", function () {
      show(index + 1, true);
    });
    // Enter в ответе не должен сдать экзамен раньше последнего вопроса.
    on(one("form", root), "submit", function (e) {
      if (index >= steps.length - 1) return;
      e.preventDefault();
      show(index + 1, true);
    });
    show(0, false);
  })();

  /* ---------- приём и сдача смены ---------- */

  all("[data-checklist]").forEach(function (form) {
    var boxes = all("[data-check]", form);
    var counter = one("[data-check-count]", form);
    if (counter && boxes.length) {
      boxes.forEach(function (box) {
        on(box, "change", function () {
          var done = boxes.filter(function (b) {
            return b.checked;
          }).length;
          counter.textContent = done + " из " + boxes.length;
        });
      });
    }
    var cash = one("[data-cash]", form);
    if (!cash) return;
    var expected = one("[data-cash-expected]", cash);
    var actual = one("[data-cash-actual]", cash);
    var out = one("[data-cash-diff]", cash);
    if (!expected || !actual || !out) return;
    var tones = ["text-primary-ink", "text-foreground", "text-muted-foreground"];
    function amount(input) {
      var raw = String(input.value || "").replace(/[\s\u00a0\u202f₽]/g, "");
      return /^\d{1,8}$/.test(raw) ? Number(raw) : null;
    }
    function paintDiff() {
      var a = amount(expected);
      var b = amount(actual);
      var text = "—";
      var tone = "text-muted-foreground";
      if (a !== null && b !== null) {
        var d = b - a;
        text = d === 0 ? "Сходится" : (d > 0 ? "+" : "−") + GP.money(Math.abs(d)) + " " + (d > 0 ? "излишек" : "недостача");
        tone = d < 0 ? "text-primary-ink" : "text-foreground";
      }
      out.textContent = text;
      tones.forEach(function (t) {
        out.classList.toggle(t, t === tone);
      });
    }
    on(expected, "input", paintDiff);
    on(actual, "input", paintDiff);
  });

  /* ---------- чистка клавиатур: режим распределения мест ---------- */

  (function cleaning() {
    var select = one("[data-assign-emp]");
    if (!select) return;
    var KEY = "gamerpro-clean-assign";
    var done = one("[data-assign-done]");
    var main = document.getElementById("main");
    function apply(id) {
      all("[data-clean-form]").forEach(function (form) {
        var action = one('input[name="_action"]', form);
        var emp = one("[data-assign-id]", form);
        if (action) action.value = id ? "assign_seats" : "toggle_clean";
        if (emp) emp.value = id;
      });
      if (done) done.classList.toggle("hidden", !id);
      if (main) main.classList.toggle("is-assigning", !!id);
    }
    function remember(id) {
      try {
        if (id) sessionStorage.setItem(KEY, id);
        else sessionStorage.removeItem(KEY);
      } catch (err) {}
    }
    try {
      var saved = sessionStorage.getItem(KEY) || "";
      if (saved && one('option[value="' + saved.replace(/[^\d]/g, "") + '"]', select)) select.value = saved;
    } catch (err) {}
    apply(select.value);
    on(select, "change", function () {
      remember(select.value);
      apply(select.value);
    });
    on(done, "click", function () {
      select.value = "";
      remember("");
      apply("");
    });
  })();
})();

/* Поиск по учёткам Langame: фильтрует строки и раскрывает блок неактивных при совпадении. */
(function () {
  document.querySelectorAll("[data-filter-input]").forEach(function (input) {
    var root = document.getElementById(input.getAttribute("data-filter-input"));
    if (!root) return;
    var rows = root.querySelectorAll("[data-filter-text]");
    var details = root.querySelector("[data-filter-details]");
    input.addEventListener("input", function () {
      var q = input.value.trim().toLowerCase();
      var hiddenHit = false;
      rows.forEach(function (li) {
        var hit = !q || li.getAttribute("data-filter-text").indexOf(q) !== -1;
        li.hidden = !hit;
        if (hit && q && details && details.contains(li)) hiddenHit = true;
      });
      if (details && q) details.open = hiddenHit || details.open;
    });
  });
})();
