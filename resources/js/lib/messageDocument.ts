export function messageDocument(html: string, dark: boolean): string {
    const safeHtml = html
        .replace(/<img\b[^>]*>/gi, (tag) => {
            const source = tag.match(/\s+src\s*=\s*(["'])(.*?)\1/i)?.[2];

            if (
                !source ||
                source.length > 349550 ||
                !/^data:image\/(?:png|jpeg);base64,[A-Za-z0-9+/]+={0,2}$/.test(
                    source,
                )
            ) {
                return '<span style="color:#71717a">[remote image blocked]</span>';
            }

            const width = tag.match(
                /\s+width\s*=\s*["']?(\d{1,4})(?:["'\s>])/i,
            )?.[1];

            return `<img src="${source}" alt=""${width && Number(width) <= 4096 ? ` width="${width}"` : ''}>`;
        })
        .replace(/<a\s/gi, '<a target="_blank" rel="noopener noreferrer" ');

    const foreground = dark ? '#e4e4e7' : '#27272a';
    const muted = dark ? '#a1a1aa' : '#71717a';
    const link = dark ? '#5eead4' : '#0d9488';

    return `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:"><style>
html{color-scheme:${dark ? 'dark' : 'light'};background:transparent}
body{margin:0;padding:1px;background:transparent;font:13px/1.6 ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:${foreground};word-break:break-word;overflow-wrap:anywhere}
${dark ? `body :where(p,div,span,td,th,li,pre,code,strong,em){color:${foreground}!important}` : ''}
img{max-width:100%;height:auto}
p{margin:0 0 .6em}p:last-child{margin-bottom:0}
ul,ol{margin:0 0 .6em;padding-left:1.4em}
blockquote{margin:0 0 .6em;padding-left:.8em;border-left:2px solid #99f6e4;color:${muted}}
pre{white-space:pre-wrap;font:12px/1.6 ui-monospace,monospace}
a{color:${link}!important}
table{max-width:100%}
</style></head><body>${safeHtml}</body></html>`;
}
