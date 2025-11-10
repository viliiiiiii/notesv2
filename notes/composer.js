(function () {
  const palette = ['#6366F1', '#0EA5E9', '#10B981', '#F59E0B', '#EC4899', '#F97316', '#14B8A6', '#A855F7', '#8B5CF6', '#EF4444'];

  function randomColor() {
    return palette[Math.floor(Math.random() * palette.length)];
  }

  function createDefaultBlock(type) {
    return {
      uid: 'blk_' + Math.random().toString(16).slice(2, 10),
      type: type || 'paragraph',
      text: '',
      checked: false,
      items: [],
      icon: null,
      color: null,
    };
  }

  function serializeBlocks(blocks) {
    return blocks.map((block) => {
      const payload = {
        uid: block.uid,
        type: block.type,
        text: block.text || '',
        checked: !!block.checked,
        items: Array.isArray(block.items) ? block.items.filter(Boolean) : [],
        icon: block.icon || null,
        color: block.color || null,
      };
      if (payload.type === 'divider') {
        payload.text = '';
        payload.checked = false;
        payload.items = [];
        payload.icon = null;
      }
      if (!['bulleted', 'numbered'].includes(payload.type)) {
        payload.items = [];
      }
      if (payload.type !== 'callout') {
        payload.icon = null;
      }
      if (payload.type !== 'todo') {
        payload.checked = false;
      }
      return payload;
    });
  }

  function blockPlaintext(block) {
    if (block.type === 'divider') {
      return '';
    }
    if (['bulleted', 'numbered'].includes(block.type)) {
      return (block.items || []).map((item) => '• ' + item).join('\n');
    }
    return block.text || '';
  }

  function updateHiddenFields(state) {
    const serialized = serializeBlocks(state.blocks);
    if (state.blocksField) {
      state.blocksField.value = JSON.stringify(serialized);
    }
    if (state.bodyFallback) {
      const plain = serialized.map(blockPlaintext).filter(Boolean).join('\n\n');
      state.bodyFallback.value = plain;
    }
  }

  function renderBlocks(state) {
    if (!state.blockList) {
      return;
    }
    state.blockList.innerHTML = '';
    state.blocks.forEach((block, index) => {
      const node = renderBlock(state, block, index);
      state.blockList.appendChild(node);
    });
    updateHiddenFields(state);
  }

  function renderBlock(state, block, index) {
    const wrapper = document.createElement('div');
    wrapper.className = 'composer-block';
    wrapper.dataset.index = String(index);

    const header = document.createElement('div');
    header.className = 'composer-block__head';

    const select = document.createElement('select');
    select.className = 'composer-block__type';
    ['paragraph','heading1','heading2','heading3','todo','bulleted','numbered','quote','callout','divider'].forEach((type) => {
      const opt = document.createElement('option');
      opt.value = type;
      opt.textContent = {
        paragraph: 'Text',
        heading1: 'Heading 1',
        heading2: 'Heading 2',
        heading3: 'Heading 3',
        todo: 'To-do',
        bulleted: 'Bulleted list',
        numbered: 'Numbered list',
        quote: 'Quote',
        callout: 'Callout',
        divider: 'Divider',
      }[type];
      if (block.type === type) {
        opt.selected = true;
      }
      select.appendChild(opt);
    });
    select.addEventListener('change', () => {
      block.type = select.value;
      if (block.type === 'divider') {
        block.text = '';
        block.items = [];
        block.icon = null;
        block.checked = false;
      }
      renderBlocks(state);
    });
    header.appendChild(select);

    const actions = document.createElement('div');
    actions.className = 'composer-block__actions';

    const btnUp = document.createElement('button');
    btnUp.type = 'button';
    btnUp.className = 'composer-block__btn';
    btnUp.textContent = '↑';
    btnUp.title = 'Move up';
    btnUp.disabled = index === 0;
    btnUp.addEventListener('click', () => {
      if (index > 0) {
        const tmp = state.blocks[index - 1];
        state.blocks[index - 1] = block;
        state.blocks[index] = tmp;
        renderBlocks(state);
      }
    });
    actions.appendChild(btnUp);

    const btnDown = document.createElement('button');
    btnDown.type = 'button';
    btnDown.className = 'composer-block__btn';
    btnDown.textContent = '↓';
    btnDown.title = 'Move down';
    btnDown.disabled = index === state.blocks.length - 1;
    btnDown.addEventListener('click', () => {
      if (index < state.blocks.length - 1) {
        const tmp = state.blocks[index + 1];
        state.blocks[index + 1] = block;
        state.blocks[index] = tmp;
        renderBlocks(state);
      }
    });
    actions.appendChild(btnDown);

    const btnDelete = document.createElement('button');
    btnDelete.type = 'button';
    btnDelete.className = 'composer-block__btn composer-block__btn--danger';
    btnDelete.textContent = '×';
    btnDelete.title = 'Remove block';
    btnDelete.addEventListener('click', () => {
      state.blocks.splice(index, 1);
      renderBlocks(state);
    });
    actions.appendChild(btnDelete);

    header.appendChild(actions);
    wrapper.appendChild(header);

    const body = document.createElement('div');
    body.className = 'composer-block__body';

    if (block.type === 'divider') {
      const divider = document.createElement('div');
      divider.className = 'composer-block__divider';
      body.appendChild(divider);
    } else {
      const textarea = document.createElement('textarea');
      textarea.className = 'composer-block__text';
      textarea.placeholder = block.type === 'quote' ? 'Quote' : 'Write something…';
      textarea.value = ['bulleted','numbered'].includes(block.type)
        ? (block.items || []).join('\n')
        : (block.text || '');
      textarea.addEventListener('input', () => {
        if (['bulleted','numbered'].includes(block.type)) {
          block.items = textarea.value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
        } else {
          block.text = textarea.value;
        }
        updateHiddenFields(state);
      });
      body.appendChild(textarea);

      if (block.type === 'todo') {
        const checkboxWrap = document.createElement('label');
        checkboxWrap.className = 'composer-block__checkbox';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = !!block.checked;
        input.addEventListener('change', () => {
          block.checked = input.checked;
          updateHiddenFields(state);
        });
        checkboxWrap.appendChild(input);
        checkboxWrap.append(' Mark complete by default');
        body.appendChild(checkboxWrap);
      }

      if (block.type === 'callout') {
        const iconLabel = document.createElement('label');
        iconLabel.className = 'composer-block__icon-input';
        iconLabel.textContent = 'Icon';
        const iconField = document.createElement('input');
        iconField.type = 'text';
        iconField.maxLength = 4;
        iconField.value = block.icon || '';
        iconField.placeholder = '💡';
        iconField.addEventListener('input', () => {
          block.icon = iconField.value.trim();
          updateHiddenFields(state);
        });
        iconLabel.appendChild(iconField);
        body.appendChild(iconLabel);
      }

      if (['bulleted','numbered'].includes(block.type)) {
        const hint = document.createElement('p');
        hint.className = 'composer-block__hint';
        hint.textContent = 'One item per line.';
        body.appendChild(hint);
      }
    }

    wrapper.appendChild(body);
    return wrapper;
  }

  function addBlock(state, type) {
    state.blocks.push(createDefaultBlock(type));
    renderBlocks(state);
  }

  function renderTags(state) {
    if (!state.tagList) {
      return;
    }
    state.tagList.innerHTML = '';
    state.tags.forEach((tag, index) => {
      const pill = document.createElement('span');
      pill.className = 'note-tag';
      const color = tag.color || randomColor();
      tag.color = color;
      pill.style.setProperty('--tag-color', color);
      pill.textContent = tag.label;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'note-tag__remove';
      btn.textContent = '×';
      btn.addEventListener('click', () => {
        state.tags.splice(index, 1);
        renderTags(state);
      });
      pill.appendChild(btn);
      state.tagList.appendChild(pill);
    });
    if (state.tagsField) {
      state.tagsField.value = JSON.stringify(state.tags.map((tag) => ({
        label: tag.label,
        color: tag.color || randomColor(),
      })));
    }
  }

  function addTag(state, rawLabel) {
    const label = rawLabel.trim();
    if (!label) {
      return;
    }
    const existing = state.tags.find((tag) => tag.label.toLowerCase() === label.toLowerCase());
    if (existing) {
      return;
    }
    state.tags.push({ label, color: randomColor() });
    renderTags(state);
  }

  function initCover(state) {
    if (!state.coverInput || !state.coverPreview) {
      return;
    }
    const apply = () => {
      const url = state.coverInput.value.trim();
      if (url) {
        state.coverPreview.style.backgroundImage = `url("${url.replace(/"/g, '%22')}")`;
        state.coverPreview.classList.add('has-cover');
      } else {
        state.coverPreview.style.backgroundImage = 'none';
        state.coverPreview.classList.remove('has-cover');
      }
    };
    state.coverApply = apply;
    state.coverInput.addEventListener('input', apply);
    if (state.coverClear) {
      state.coverClear.addEventListener('click', () => {
        state.coverInput.value = '';
        apply();
      });
    }
    apply();
  }

  function initIcon(state) {
    if (!state.iconInput || !state.iconPreview) {
      return;
    }
    const apply = () => {
      const val = state.iconInput.value.trim();
      state.iconPreview.textContent = val || '📄';
    };
    state.iconApply = apply;
    state.iconInput.addEventListener('input', apply);
    apply();
  }

  function cloneBlockForState(block) {
    return {
      uid: 'blk_' + Math.random().toString(16).slice(2, 10),
      type: block.type || 'paragraph',
      text: block.text || '',
      checked: !!block.checked,
      items: Array.isArray(block.items) ? block.items.map((item) => String(item)) : [],
      icon: block.icon || null,
      color: block.color || null,
    };
  }

  function applyTemplate(state, template) {
    if (!template) {
      return;
    }
    if (state.titleField && template.title) {
      state.titleField.value = template.title;
    }
    if (state.statusField && template.status) {
      const match = Array.from(state.statusField.options || []).find((opt) => opt.value === template.status);
      state.statusField.value = match ? template.status : state.statusField.value;
    }
    if (state.propertyFields) {
      const props = template.properties || {};
      if (state.propertyFields.project) {
        state.propertyFields.project.value = props.project || '';
      }
      if (state.propertyFields.location) {
        state.propertyFields.location.value = props.location || '';
      }
      if (state.propertyFields.due_date) {
        state.propertyFields.due_date.value = props.due_date || '';
      }
      if (state.propertyFields.priority) {
        const priority = props.priority || '';
        const matchPriority = Array.from(state.propertyFields.priority.options || []).find((opt) => opt.value === priority);
        state.propertyFields.priority.value = matchPriority ? priority : state.propertyFields.priority.value;
      }
    }
    if (state.iconInput) {
      state.iconInput.value = template.icon || '';
      if (state.iconApply) {
        state.iconApply();
      }
    }
    if (state.coverInput) {
      state.coverInput.value = template.coverUrl || '';
      if (state.coverApply) {
        state.coverApply();
      }
    }
    const templateTags = Array.isArray(template.tags) ? template.tags : [];
    state.tags = templateTags
      .map((tag) => ({
        label: String(tag.label || ''),
        color: tag.color || null,
      }))
      .filter((tag) => tag.label.trim() !== '');
    const templateBlocks = Array.isArray(template.blocks) && template.blocks.length
      ? template.blocks
      : [createDefaultBlock('paragraph')];
    state.blocks = templateBlocks.map((block) => cloneBlockForState(block));
    renderBlocks(state);
    renderTags(state);
  }

  function resetToBlank(state) {
    state.blocks = [createDefaultBlock('paragraph')];
    state.tags = [];
    if (state.iconInput) {
      state.iconInput.value = '';
      if (state.iconApply) {
        state.iconApply();
      }
    }
    if (state.coverInput) {
      state.coverInput.value = '';
      if (state.coverApply) {
        state.coverApply();
      }
    }
    if (state.propertyFields) {
      if (state.propertyFields.project) state.propertyFields.project.value = '';
      if (state.propertyFields.location) state.propertyFields.location.value = '';
      if (state.propertyFields.due_date) state.propertyFields.due_date.value = '';
      if (state.propertyFields.priority && state.defaultPriority) {
        state.propertyFields.priority.value = state.defaultPriority;
      }
    }
    if (state.statusField && state.defaultStatus) {
      state.statusField.value = state.defaultStatus;
    }
    renderBlocks(state);
    renderTags(state);
  }

  function initComposer(el) {
    const config = JSON.parse(el.dataset.config || '{}');
    const state = {
      root: el,
      blocks: Array.isArray(config.blocks) && config.blocks.length ? config.blocks.map((b) => ({
        uid: b.uid || 'blk_' + Math.random().toString(16).slice(2, 10),
        type: b.type || 'paragraph',
        text: b.text || '',
        checked: !!b.checked,
        items: Array.isArray(b.items) ? b.items : [],
        icon: b.icon || null,
        color: b.color || null,
      })) : [createDefaultBlock('paragraph')],
      tags: Array.isArray(config.tags) ? config.tags.map((tag) => ({
        label: String(tag.label || ''),
        color: tag.color || randomColor(),
      })).filter((tag) => tag.label.trim() !== '') : [],
      templates: Array.isArray(config.templates) ? config.templates : [],
      blockList: el.querySelector('[data-block-list]'),
      blocksField: el.querySelector('[data-blocks-field]'),
      bodyFallback: el.querySelector('[data-body-fallback]'),
      toolbar: el.querySelector('[data-block-toolbar]'),
      tagList: el.querySelector('[data-tag-list]'),
      tagInput: el.querySelector('[data-tag-input]'),
      tagsField: el.querySelector('[data-tags-field]'),
      coverInput: el.querySelector('[data-cover-input]'),
      coverPreview: el.querySelector('[data-cover-preview]'),
      coverClear: el.querySelector('[data-cover-clear]'),
      iconInput: el.querySelector('[data-icon-input]'),
      iconPreview: el.querySelector('[data-icon-preview]'),
      templateSelect: el.querySelector('[data-template-select]'),
      templateApplyBtn: el.querySelector('[data-template-apply]'),
      templateClearBtn: el.querySelector('[data-template-clear]'),
    };

    const form = el.closest('form');
    state.form = form || null;
    if (form) {
      state.titleField = form.querySelector('input[name="title"]');
      state.statusField = form.querySelector('select[name="status"]');
      state.propertyFields = {
        project: form.querySelector('input[name="property_project"]'),
        location: form.querySelector('input[name="property_location"]'),
        due_date: form.querySelector('input[name="property_due_date"]'),
        priority: form.querySelector('select[name="property_priority"]'),
      };
      if (state.propertyFields && state.propertyFields.priority) {
        state.defaultPriority = state.propertyFields.priority.value;
      }
      if (state.statusField) {
        state.defaultStatus = state.statusField.value;
      }
    }

    if (state.toolbar) {
      state.toolbar.addEventListener('click', (event) => {
        const btn = event.target.closest('[data-add-block]');
        if (!btn) {
          return;
        }
        event.preventDefault();
        addBlock(state, btn.getAttribute('data-add-block'));
      });
    }

    if (state.tagInput) {
      state.tagInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ',' || event.key === 'Tab') {
          if (event.key !== 'Tab') {
            event.preventDefault();
          }
          addTag(state, state.tagInput.value);
          state.tagInput.value = '';
        }
      });
      state.tagInput.addEventListener('blur', () => {
        if (state.tagInput.value.trim() !== '') {
          addTag(state, state.tagInput.value);
          state.tagInput.value = '';
        }
      });
    }

    if (el.closest('form')) {
      el.closest('form').addEventListener('submit', () => {
        renderBlocks(state);
        renderTags(state);
      });
    }

    if (state.templateApplyBtn && state.templateSelect) {
      state.templateApplyBtn.addEventListener('click', () => {
        const selected = state.templateSelect.value;
        if (!selected) {
          return;
        }
        const template = state.templates.find((tpl) => String(tpl.id) === selected);
        applyTemplate(state, template);
      });
    }

    if (state.templateClearBtn) {
      state.templateClearBtn.addEventListener('click', () => {
        if (state.templateSelect) {
          state.templateSelect.value = '';
        }
        resetToBlank(state);
      });
    }

    if (state.templateSelect) {
      state.templateSelect.addEventListener('change', () => {
        const selected = state.templateSelect.value;
        if (!selected) {
          return;
        }
        const template = state.templates.find((tpl) => String(tpl.id) === selected);
        if (template) {
          applyTemplate(state, template);
        }
      });
    }

    initCover(state);
    initIcon(state);
    renderBlocks(state);
    renderTags(state);
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-note-composer]').forEach((el) => initComposer(el));
  });
})();
