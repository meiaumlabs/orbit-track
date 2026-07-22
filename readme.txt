=== Orbit Track — Tracking Orgânico & Anúncios ===
Contributors: 61labs
Tags: analytics, tracking, utm, estatisticas, visitantes, origem, dispositivos
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tracking profissional e independente para WordPress: origem do tráfego (orgânico, anúncios, social, e-mail), páginas, tempo, região e dispositivo — direto no painel.

== Description ==

O **Orbit Track** é um plugin de analytics self-hosted que mapeia todos os acessos do seu site sem depender de serviços externos como o Google Analytics. Os dados ficam no seu próprio banco de dados.

**O que ele mede:**

* **Aquisição / origem do tráfego** com atribuição de canal no modelo GA4/WP Statistics: Direto, Busca orgânica, Busca paga (Ads), Social orgânico, Social pago (Ads), E-mail, Display, Afiliados e Referência.
* **Detecção de anúncios** por parâmetros UTM e click IDs das plataformas: `gclid`/`gbraid`/`wbraid` (Google Ads), `msclkid` (Microsoft/Bing Ads), `ttclid` (TikTok), `twclid` (X), `li_fat_id` (LinkedIn) e outros.
* **Campanhas UTM**: origem, mídia, campanha, termo e conteúdo.
* **Páginas**: mais vistas, páginas de entrada (landing) e tempo médio por página.
* **Público**: tipo de dispositivo (desktop, celular, tablet, TV), navegador e sistema operacional.
* **Regiões mais acessadas**: país, estado/região e cidade (geolocalização por IP).
* **Tempo de permanência** por página e duração da sessão, com taxa de rejeição e páginas por sessão.
* **Série temporal** de sessões e visualizações.
* **Log de acessos ao vivo**: acompanhe cada visita no instante em que acontece — página, canal, país/cidade, dispositivo, navegador e SO — com contador de "online agora" e atualização automática.
* **Mapa-múndi de visitantes**: veja de onde vêm seus acessos num mapa interativo, colorido pela intensidade de sessões por país.
* **Links de saída (outbound)**: registre os cliques em links externos que os visitantes seguem ao sair do site, com os domínios mais clicados.
* **Metas de conversão**: defina metas pela URL da página (ex.: `/obrigado`) e acompanhe conversões, visitantes e taxa de conversão — sem limite de metas.

**Privacidade e desempenho:**

* Cookieless — usa `localStorage` com identificador anônimo, sem cookies de rastreamento.
* O endereço IP **nunca** é armazenado: o visitante é identificado por um hash com salt, e só o resultado geográfico agregado é salvo.
* Compatível com plugins de cache (captura via beacon JavaScript, não no PHP da página).
* Filtro de bots e crawlers, exclusão de papéis logados (ex.: administradores) e respeito opcional ao "Do Not Track".
* Retenção de dados configurável.

== Installation ==

1. Envie a pasta `orbit-track` para `/wp-content/plugins/` ou instale o ZIP pelo painel.
2. Ative o plugin em "Plugins".
3. Acesse o menu **Orbit Track**. A coleta começa automaticamente nas visitas do site.

== Frequently Asked Questions ==

= Preciso configurar alguma chave de API? =
Não. A geolocalização usa cabeçalhos do servidor/CDN (ex.: Cloudflare) quando disponíveis e, como fallback, um provedor gratuito sem chave. Você pode desligar a geolocalização nas Configurações.

= Funciona com cache de página? =
Sim. A captura acontece via um beacon JavaScript executado no navegador, então funciona mesmo com páginas totalmente cacheadas.

= Ele usa cookies? =
Não para rastreamento. O identificador de visitante fica no `localStorage` do navegador e é anonimizado por hash no servidor.

== Changelog ==

= 1.4.0 =
* Visual: novo tema com a identidade de marca da 61 Labs — acento "signal" (lime) e logo em destaque, substituindo o índigo genérico.
* Acessibilidade: contraste elevado para o padrão WCAG AA (texto secundário, indicadores positivos e links agora legíveis sobre o fundo claro).
* Espaçamento: ritmo vertical entre as seções padronizado para uma leitura mais consistente.
* Novo: assinatura da 61 Labs no rodapé do painel, com link para https://61labs.com.br.

= 1.3.1 =
* Novo: **Exportar CSV** — botão "⬇ Exportar CSV" disponível em todas as abas com dados (Dashboard, Aquisição, Público, Conteúdo, Metas, Ao vivo, Segurança). Cada CSV inclui todas as tabelas da aba com cabeçalhos de seção, BOM UTF-8 para compatibilidade com Excel e nome de arquivo com a data do export.

= 1.3.0 =
* Novo: **Log de origem visível** — canal "Referência" e outros agora exibem o domínio de onde o visitante veio diretamente no log ao vivo.
* Novo: **IP do visitante** — armazenamento e exibição do IP opcionais (opt-in via Configurações, padrão OFF para conformidade com a LGPD). Suporte a anonimização (exibe `203.0.113.x` em vez do IP completo).
* Novo: **Blacklist de IPs** — aba "Segurança" para gerenciar IPs bloqueados. Visitantes bloqueados recebem 403. Adicione via botão no log ao vivo ou manualmente. Nota: em sites com cache de página completa, o bloqueio se aplica a requisições não-cacheadas.
* Novo: **Bots sinalizados no log** — acessos de bots/crawlers sempre aparecem no log ao vivo com badge "bot", independente da configuração "Excluir bots". Estatísticas continuam excluindo bots.
* Novo: **Detecção de navegação privada/anônima** — heurística JS por quota de storage; exibe badge "privado" no log quando detectado.
* Novo: **ID parcial de sessão** — exibido no log para correlação de acessos.
* Melhoria: bots agora são registrados no banco com flag `is_bot=1` e todas as queries de estatísticas filtram `is_bot = 0`.

= 1.2.1 =
* Correção: gráficos de "Sessões e visualizações no tempo" e "Canais de aquisição" cresciam infinitamente (loop de resize do Chart.js). Canvas envolvido em wrapper com altura fixa (`position: relative; height: Xpx`).

= 1.2.0 =
* Melhoria: **UI/UX redesenhada** — painel administrativo com novo visual, tipografia e layout modernizados.
* Melhoria: **Coleta alinhada ao SlimStat** — precisão de rastreamento equivalente ao SlimStat, mantendo arquitetura self-hosted, cookieless e sem serviços pagos.

= 1.1.0 =
* Novo: **Log de acessos ao vivo** (aba "Ao vivo") com visão visita-a-visita, contador de visitantes online e atualização automática.
* Novo: **Mapa-múndi de visitantes** na aba "Público", colorindo os países pela quantidade de sessões.
* Novo: **Rastreamento de links de saída** (outbound) com relatório de links e domínios externos mais clicados, na aba "Conteúdo".
* Novo: **Metas de conversão** (aba "Metas") por correspondência de URL, com conversões, visitantes e taxa de conversão — sem limite de metas.
* Recursos inspirados no SlimStat, mantendo tudo self-hosted, cookieless e sem serviços pagos.

= 1.0.0 =
* Versão inicial: captura de sessões e pageviews, atribuição de canal (orgânico/pago/social/e-mail/referência), detecção de dispositivo/navegador/SO, geolocalização por IP com cache, tempo de permanência, painel com KPIs, gráficos e relatórios por aquisição, público e conteúdo.
