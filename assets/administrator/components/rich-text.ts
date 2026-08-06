import { LitElement, html } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('kumwe-rich-text')
export class KumweRichText extends LitElement {
  override createRenderRoot(): HTMLElement { return this; }

  override firstUpdated(): void {
    const source = this.querySelector<HTMLTextAreaElement>('textarea');
    const editor = this.querySelector<HTMLElement>('[data-rich-text-editor]');
    if (!source || !editor) return;

    editor.innerHTML = this.toEditorHtml(source.value);
    editor.addEventListener('input', () => {
      source.value = this.toSource(editor);
      source.dispatchEvent(new Event('input', { bubbles: true }));
    });
    source.addEventListener('invalid', () => editor.focus());
    this.querySelectorAll<HTMLButtonElement>('[data-rich-text-command]').forEach((button) => {
      button.addEventListener('click', () => {
        const command = button.dataset.richTextCommand;
        editor.focus();
        if (command === 'createLink') {
          const url = window.prompt('Link URL');
          if (url) document.execCommand('createLink', false, url);
        } else if (command === 'formatBlock') {
          document.execCommand('formatBlock', false, 'h2');
        } else if (command) {
          document.execCommand(command);
        }
        editor.dispatchEvent(new Event('input', { bubbles: true }));
      });
    });
  }

  override render() {
    return html`
      <div class="rich-text-shell js-only">
        <div class="rich-text-toolbar" role="toolbar" aria-label="Text formatting">
          <button type="button" data-rich-text-command="bold" aria-label="Bold"><strong>B</strong></button>
          <button type="button" data-rich-text-command="formatBlock" aria-label="Heading">Heading</button>
          <button type="button" data-rich-text-command="insertUnorderedList" aria-label="Bulleted list">List</button>
          <button type="button" data-rich-text-command="createLink" aria-label="Add link">Link</button>
        </div>
        <div class="rich-text-editor" contenteditable="true" role="textbox" aria-multiline="true"
          aria-label="Rich text editor" data-rich-text-editor></div>
        <p class="field-help">Use the toolbar for headings, emphasis, lists, and safe links.</p>
      </div>
      <slot></slot>
    `;
  }

  private toEditorHtml(source: string): string {
    const lines = source.replace(/\r\n?/g, '\n').split('\n');
    const blocks: string[] = [];
    let list: string[] = [];
    const flushList = (): void => {
      if (list.length === 0) return;
      blocks.push(`<ul>${list.map((item) => `<li>${this.inlineToHtml(item)}</li>`).join('')}</ul>`);
      list = [];
    };
    for (const line of lines) {
      if (line.startsWith('- ')) {
        list.push(line.slice(2));
        continue;
      }
      flushList();
      if (line.startsWith('## ')) blocks.push(`<h2>${this.inlineToHtml(line.slice(3))}</h2>`);
      else if (line.trim() === '') blocks.push('<p><br></p>');
      else blocks.push(`<p>${this.inlineToHtml(line)}</p>`);
    }
    flushList();
    return blocks.join('');
  }

  private inlineToHtml(source: string): string {
    const escaped = source
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
    return escaped
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/\[([^\]]+)]\((https?:\/\/[^\s)]+|mailto:[^\s)]+|\/(?!\/)[^\s)]*|#[^\s)]+)\)/g, '<a href="$2">$1</a>');
  }

  private toSource(editor: HTMLElement): string {
    return Array.from(editor.children)
      .map((element) => this.blockToSource(element))
      .filter((value, index, all) => value !== '' || (index > 0 && all[index - 1] !== ''))
      .join('\n');
  }

  private blockToSource(element: Element): string {
    if (element.tagName === 'UL' || element.tagName === 'OL') {
      return Array.from(element.children)
        .map((item) => `- ${this.inlineToSource(item)}`)
        .join('\n');
    }
    const prefix = /^H[1-6]$/.test(element.tagName) ? '## ' : '';
    return `${prefix}${this.inlineToSource(element)}`.trimEnd();
  }

  private inlineToSource(element: Element): string {
    let output = '';
    element.childNodes.forEach((node) => {
      if (node.nodeType === Node.TEXT_NODE) output += node.textContent ?? '';
      else if (node instanceof HTMLBRElement) output += '\n';
      else if (node instanceof HTMLAnchorElement) output += `[${this.inlineToSource(node)}](${node.href})`;
      else if (node instanceof HTMLElement && ['B', 'STRONG'].includes(node.tagName)) {
        output += `**${this.inlineToSource(node)}**`;
      } else if (node instanceof Element) output += this.inlineToSource(node);
    });
    return output;
  }
}

declare global { interface HTMLElementTagNameMap { 'kumwe-rich-text': KumweRichText; } }
