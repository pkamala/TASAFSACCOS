const exportEncoder = new TextEncoder();

function exportXml(text) {
    return String(text).replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f\ufffe\uffff]/g, '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function exportCellText(cell) {
    const copy = cell.cloneNode(true);
    copy.querySelectorAll('form, button, input, select, textarea, script, style, [hidden], .print-hide').forEach(node => node.remove());
    return copy.textContent.replace(/\s+/g, ' ').trim();
}

function exportRows(rows) {
    return Array.from(rows).filter(row => !row.hidden && !row.matches('.print-hide'))
        .map(row => Array.from(row.cells).map(cell => ({
            text: exportCellText(cell),
            columns: cell.colSpan,
            rows: cell.rowSpan || 1,
            heading: cell.tagName === 'TH',
            money: cell.classList.contains('money'),
        })));
}

function exportBody(table) {
    return Array.from(table.tBodies).flatMap(body => exportRows(Array.from(body.rows)
        .filter(row => !(row.cells.length === 1 && row.cells[0].matches('td[colspan]')))));
}

async function exportAllPages(controls) {
    const pageKey = controls.dataset.serverTablePagination;
    const size = controls.querySelector('.page-size select');
    const url = new URL(location.href);
    url.searchParams.set(pageKey, '1');
    url.searchParams.set(size.name, size.options[size.options.length - 1].value);
    const rows = [];
    const visited = new Set();
    let footer = [];
    do {
        if (url.origin !== location.origin || visited.has(url.href)) throw new Error('Invalid report page');
        visited.add(url.href);
        const response = await fetch(url);
        if (!response.ok || response.redirected) throw new Error('Unable to load report page');
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const pagination = Array.from(page.querySelectorAll('[data-server-table-pagination]'))
            .find(element => element.dataset.serverTablePagination === pageKey);
        const sibling = pagination?.previousElementSibling;
        const table = sibling?.matches('table') ? sibling : sibling?.querySelector('table');
        if (!table) throw new Error('Unable to locate report table');
        rows.push(...exportBody(table));
        footer = exportRows(table.tFoot?.rows ?? []);
        const next = pagination.querySelector('a[rel="next"]');
        if (!next) break;
        url.href = new URL(next.getAttribute('href'), url).href;
    } while (rows.length < Number(controls.dataset.exportTotal));

    if (rows.length !== Number(controls.dataset.exportTotal)) throw new Error('Report changed during export');
    return {rows, footer};
}

const exportCrcTable = Uint32Array.from({length: 256}, (_, index) => {
    let value = index;
    for (let bit = 0; bit < 8; bit++) value = (value >>> 1) ^ (value & 1 ? 0xedb88320 : 0);
    return value;
});

function exportZip(files, mimeType) {
    const data = [];
    const directory = [];
    let offset = 0;
    let directorySize = 0;

    for (const [path, xml] of Object.entries(files)) {
        const name = exportEncoder.encode(path);
        const bytes = exportEncoder.encode(xml);
        let crc = 0xffffffff;
        for (const byte of bytes) crc = (crc >>> 8) ^ exportCrcTable[(crc ^ byte) & 255];
        crc = (crc ^ 0xffffffff) >>> 0;

        const local = new DataView(new ArrayBuffer(30));
        local.setUint32(0, 0x04034b50, true);
        local.setUint16(4, 20, true);
        local.setUint16(12, 33, true);
        local.setUint32(14, crc, true);
        local.setUint32(18, bytes.length, true);
        local.setUint32(22, bytes.length, true);
        local.setUint16(26, name.length, true);
        data.push(local.buffer, name, bytes);

        const entry = new DataView(new ArrayBuffer(46));
        entry.setUint32(0, 0x02014b50, true);
        entry.setUint16(4, 20, true);
        new Uint8Array(entry.buffer, 6, 26).set(new Uint8Array(local.buffer, 4, 26));
        entry.setUint32(42, offset, true);
        directory.push(entry.buffer, name);
        directorySize += entry.byteLength + name.length;
        offset += local.byteLength + name.length + bytes.length;
    }

    const end = new DataView(new ArrayBuffer(22));
    end.setUint32(0, 0x06054b50, true);
    end.setUint16(8, directory.length / 2, true);
    end.setUint16(10, directory.length / 2, true);
    end.setUint32(12, directorySize, true);
    end.setUint32(16, offset, true);
    return new Blob([...data, ...directory, end.buffer], {type: mimeType});
}

const exportOffice = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const exportMime = 'application/vnd.openxmlformats-officedocument';

function exportRelationships(items) {
    return '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        + items.map(([type, target], index) => `<Relationship Id="rId${index + 1}" Type="${exportOffice}/${type}" Target="${target}"/>`).join('')
        + '</Relationships>';
}

function exportContentTypes(parts) {
    return '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        + '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        + parts.map(([path, type]) => `<Override PartName="/${path}" ContentType="${exportMime}.${type}+xml"/>`).join('')
        + '</Types>';
}

function exportGrid(rows, render) {
    const occupied = [];
    return rows.map((cells, row) => {
        let column = 0;
        return cells.map(cell => {
            while (occupied[column] > row) column++;
            const start = column;
            for (; column < start + cell.columns; column++) occupied[column] = row + cell.rows;
            return render(cell, row, start);
        });
    });
}

function exportWord(title, headers, body, footer) {
    const paragraph = (text, bold = false) => `<w:p><w:r>${bold ? '<w:rPr><w:b/></w:rPr>' : ''}<w:t xml:space="preserve">${exportXml(text)}</w:t></w:r></w:p>`;
    const grid = [];
    const rows = exportGrid([...headers, ...body, ...footer], (cell, row, column) => {
        for (let index = row; index < row + cell.rows; index++) {
            grid[index] ??= [];
            grid[index][column] = index === row ? cell : {...cell, text: '', continuation: true};
        }
    });
    const columns = grid.reduce((width, row) => row.reduce((span, cell, index) => Math.max(span, index + cell.columns), width), 1);
    const table = rows.map((_, index) => `<w:tr>${index < headers.length ? '<w:trPr><w:tblHeader/></w:trPr>' : ''}`
        + (grid[index] ?? []).map(cell => `<w:tc><w:tcPr><w:gridSpan w:val="${cell.columns}"/>`
            + (cell.continuation ? '<w:vMerge/>' : cell.rows > 1 ? '<w:vMerge w:val="restart"/>' : '')
            + `</w:tcPr>${paragraph(cell.text, cell.heading)}</w:tc>`).join('') + '</w:tr>').join('');
    const path = 'word/document.xml';
    return exportZip({
        '[Content_Types].xml': exportContentTypes([[path, 'wordprocessingml.document.main']]),
        '_rels/.rels': exportRelationships([['officeDocument', path]]),
        [path]: '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            + paragraph(title, true) + '<w:tbl><w:tblPr><w:tblBorders>'
            + ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'].map(side => `<w:${side} w:val="single" w:sz="4" w:color="B0B0B0"/>`).join('')
            + '</w:tblBorders></w:tblPr><w:tblGrid>'
            + '<w:gridCol/>'.repeat(columns) + `</w:tblGrid>${table}</w:tbl>`
            + '<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/></w:sectPr></w:body></w:document>',
    }, `${exportMime}.wordprocessingml.document`);
}

function exportExcel(headers, body, footer, template) {
    const namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    const workbook = 'xl/workbook.xml';
    const sheet = 'xl/worksheets/sheet1.xml';
    const isTrialBalance = template === 'trial-balance';
    const table = 'xl/tables/table1.xml';
    const styles = 'xl/styles.xml';
    const merged = [];
    const widths = [];
    const address = (row, column) => {
        let letters = '';
        for (let number = column + 1; number > 0; number = Math.floor((number - 1) / 26)) {
            letters = String.fromCharCode(65 + (number - 1) % 26) + letters;
        }
        return letters + (row + 1);
    };
    const rows = exportGrid([...headers, ...body, ...footer], (cell, row, column) => {
        const ref = address(row, column);
        if (cell.columns === 1) widths[column] = Math.min(48, Math.max(widths[column] ?? 10, cell.text.length + 3));
        if (cell.columns > 1 || cell.rows > 1) merged.push(`${ref}:${address(row + cell.rows - 1, column + cell.columns - 1)}`);
        const numeric = cell.text.replace(/,/g, '');
        if (cell.money && /^-?\d+(\.\d+)?$/.test(numeric)) return `<c r="${ref}"${isTrialBalance ? ' s="1"' : ''}><v>${numeric}</v></c>`;
        return `<c r="${ref}" t="inlineStr"><is><t xml:space="preserve">${exportXml(cell.text)}</t></is></c>`;
    });
    const parts = [[workbook, 'spreadsheetml.sheet.main'], [sheet, 'spreadsheetml.worksheet']];
    const relationships = [['worksheet', 'worksheets/sheet1.xml']];
    const files = {};
    const sheetName = isTrialBalance ? 'Sheet1' : 'Report';
    if (isTrialBalance) {
        const range = `A1:J${rows.length}`;
        widths.splice(0, widths.length, 14.71, 7, 15.86, 12.14, 43.43, 17.71, 15.29, 16, 16, 17.71);
        parts.push([styles, 'spreadsheetml.styles'], [table, 'spreadsheetml.table']);
        relationships.push(['styles', 'styles.xml']);
        files['xl/worksheets/_rels/sheet1.xml.rels'] = exportRelationships([['table', '../tables/table1.xml']]);
        files[table] = `<table xmlns="${namespace}" id="1" name="Table1" displayName="Table1" ref="${range}" totalsRowShown="0">`
            + `<autoFilter ref="${range}"/><tableColumns count="10">`
            + headers[0].map((cell, index) => `<tableColumn id="${index + 1}" name="${exportXml(cell.text)}"/>`).join('')
            + '</tableColumns><tableStyleInfo name="TableStyleMedium2" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/></table>';
        files[styles] = `<styleSheet xmlns="${namespace}"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>`
            + '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            + '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            + '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            + '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            + '<xf numFmtId="43" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            + '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
    return exportZip({
        ...files,
        '[Content_Types].xml': exportContentTypes(parts),
        '_rels/.rels': exportRelationships([['officeDocument', workbook]]),
        'xl/_rels/workbook.xml.rels': exportRelationships(relationships),
        [workbook]: `<workbook xmlns="${namespace}" xmlns:r="${exportOffice}"><sheets><sheet name="${sheetName}" sheetId="1" r:id="rId1"/></sheets></workbook>`,
        [sheet]: `<worksheet xmlns="${namespace}" xmlns:r="${exportOffice}">`
            + `<cols>${Array.from(widths, (width, index) => `<col min="${index + 1}" max="${index + 1}" width="${width ?? 10}" customWidth="1"/>`).join('')}</cols><sheetData>`
            + rows.map((row, index) => `<row r="${index + 1}">${row.join('')}</row>`).join('')
            + '</sheetData>' + (merged.length ? `<mergeCells>${merged.map(ref => `<mergeCell ref="${ref}"/>`).join('')}</mergeCells>` : '')
            + (isTrialBalance ? '<tableParts count="1"><tablePart r:id="rId1"/></tableParts>' : '')
            + '</worksheet>',
    }, `${exportMime}.spreadsheetml.sheet`);
}

function exportPrint(frame, title, headers, body, footer) {
    const section = (tag, rows) => `<${tag}>` + rows.map(row => '<tr>' + row.map(cell => {
        const cellTag = cell.heading ? 'th' : 'td';
        return `<${cellTag} colspan="${cell.columns}" rowspan="${cell.rows}">${exportXml(cell.text)}</${cellTag}>`;
    }).join('') + '</tr>').join('') + `</${tag}>`;
    const doc = frame.contentDocument;
    doc.open();
    doc.write(`<!doctype html><html lang="en"><meta charset="utf-8"><title>${exportXml(title)}</title>
        <style>@page{size:A4 landscape;margin:12mm}body{font:10pt sans-serif;color:#111}table{width:100%;border-collapse:collapse}
        th,td{border:1px solid #aaa;padding:.35em;text-align:left;overflow-wrap:anywhere}thead{display:table-header-group}tr{break-inside:avoid}</style>
        <h1>${exportXml(title)}</h1><table>${section('thead', headers)}${section('tbody', body)}${section('tfoot', footer)}</table></html>`);
    doc.close();
    frame.contentWindow.addEventListener('afterprint', () => frame.remove(), {once: true});
    frame.contentWindow.focus();
    frame.contentWindow.print();
}

function addTableExport(table) {
    if (table.dataset.exportReady || !table.tHead) return;
    table.dataset.exportReady = 'true';
    const wrapper = table.closest('.table-wrap') ?? table;
    const sibling = wrapper.nextElementSibling;
    const pagination = sibling?.matches('[data-server-table-pagination]') ? sibling : null;
    const pageTitle = document.querySelector('main h1')?.textContent.trim() || 'Report';
    const sectionTitle = table.caption?.textContent.trim()
        || table.closest('section')?.querySelector('h2, h3')?.textContent.trim();
    const reportTitle = sectionTitle && sectionTitle !== pageTitle ? `${pageTitle} - ${sectionTitle}` : pageTitle;
    const from = document.querySelector('main input[name="from"][type="date"]')?.value;
    const to = document.querySelector('main input[name="to"][type="date"]')?.value;
    const asOf = document.querySelector('main input[name="as_of"][type="date"]')?.value;
    const dates = asOf ? `as of ${asOf}` : [from && `from ${from}`, to && `through ${to}`].filter(Boolean).join(' ');
    const title = dates ? `${reportTitle} (${dates})` : reportTitle;
    const toolbar = document.createElement('div');
    toolbar.className = 'table-pagination print-hide';
    toolbar.setAttribute('role', 'group');
    toolbar.setAttribute('aria-label', `Export ${title}`);
    const status = document.createElement('span');
    status.setAttribute('role', 'status');
    const buttons = ['PDF', 'Word', 'Excel'].map(format => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'quiet';
        button.textContent = `Export ${format}`;
        button.addEventListener('click', async () => {
            let frame;
            if (format === 'PDF') {
                frame = document.createElement('iframe');
                frame.title = title;
                frame.style.cssText = 'position:fixed;width:1px;height:1px;opacity:0;pointer-events:none';
                document.body.append(frame);
            }
            buttons.forEach(control => { control.disabled = true; });
            status.textContent = 'Preparing export…';
            try {
                const headers = exportRows(table.tHead.rows);
                const visibleRows = exportBody(table);
                const filtered = Array.from(table.tBodies).some(body => body.querySelector('tr[hidden]'));
                const {rows, footer} = pagination && Number(pagination.dataset.exportTotal) > visibleRows.length
                    ? await exportAllPages(pagination)
                    : {rows: visibleRows, footer: filtered ? [] : exportRows(table.tFoot?.rows ?? [])};
                if (frame) {
                    exportPrint(frame, title, headers, rows, footer);
                    status.textContent = 'Choose Save as PDF in the print dialog.';
                } else {
                    const word = format === 'Word';
                    const blob = word ? exportWord(title, headers, rows, footer) : exportExcel(headers, rows, footer, table.dataset.exportTemplate);
                    const url = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.href = url;
                    link.download = `${title.replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-|-$/g, '').toLowerCase() || 'report'}.${word ? 'docx' : 'xlsx'}`;
                    link.click();
                    setTimeout(() => URL.revokeObjectURL(url), 1000);
                    status.textContent = `${format} export ready.`;
                }
            } catch {
                frame?.remove();
                status.textContent = 'The table could not be exported. Refresh and try again.';
            } finally {
                buttons.forEach(control => { control.disabled = false; });
            }
        });
        return button;
    });
    toolbar.append(...buttons, status);
    wrapper.before(toolbar);
}
