# Licence

Copyright (c) 2026 Brent Passmore. All rights reserved.

The source of this software is published so that it can be installed with
Composer, inspected by the people who rely on it, and audited by the people they
answer to. Publishing the source is not a grant of ownership.

**This software is sold.** A licence is bought on the Statamic Marketplace and
covers one production site. Local development and continuous integration are
free, as Statamic's own terms allow, so you can try it before you buy it.

Permission is granted to any person holding a licence for this software (the
"Software") to use, copy and modify it on the site the licence covers, subject
to the conditions below.

1. **One licence, one production site.** Each production installation needs its
   own licence. Statamic shows a notice in the control panel when one is
   missing; the Software itself does not stop working, and that is deliberate.

2. **Not for resale or reuse in another product.** The Software, in whole or in
   part, may not be redistributed, resold, sublicensed, or reused as the basis
   of another product without written permission.

   Reading the code to learn from it is expected and encouraged. Shipping it as
   your own is not.

3. **Keep the notice.** This licence and the copyright notice stay with any copy
   or substantial portion of the Software.

4. **Follow the law.** Use of the Software must not break any applicable law or
   regulation, nor infringe anyone else's rights.

Failing any of these conditions ends the permission granted here, immediately and
automatically.

## No warranty, and specifically no conformance claim

The capitals are below, as usual. This part is in plain words because it is the
part people actually need.

This Software cannot tell you that a document is accessible, and nothing it
produces is a conformance claim. It reads the structure of a file — a PDF's tags,
language, title and outline; the alternative text, headings and table headers in
Word, PowerPoint and Excel — and reports what is mechanically detectable there.
A great deal of PDF/UA is not mechanically detectable at all. Whether alternative
text describes the image, whether a reading order makes sense, whether a table's
headers describe its data: those are judgements, and no automated check makes
them. A problem this Software reports is a problem. A document it reports nothing
on has not been proven to have none.

**It inventories and triages. It does not remediate**, and finding a problem is
not fixing it.

Two engines can produce these results and they do not carry the same weight. The
built-in checks are heuristics. veraPDF, when installed, performs authoritative
PDF/UA-1 validation. Every check records which engine produced it and at which
version, and every report states how many documents were read by each and which
of the two that was. Read what it says ran, not just what it found. A finding
from the heuristics is not a PDF/UA validation failure, and the Software does not
present it as one.

Even a file that passes veraPDF is not thereby accessible. PDF/UA conformance is
necessary, not sufficient: a document can satisfy every clause of the standard
and still be incomprehensible to the person it was written for.

Some documents cannot be read at all — encrypted, corrupt, or in a legacy format
this Software deliberately does not parse. Those are recorded as unchecked, with
the reason, rather than counted as passing. An empty result for such a file means
nothing was looked at.

Every report states what it does not cover, and that statement cannot be removed
by configuration.

Deciding whether a document library meets a legal or contractual accessibility
obligation is work for a person, and this Software does not do it, replace it, or
provide evidence sufficient for it.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE, ACCESSIBILITY CONFORMANCE, AND NONINFRINGEMENT. IN NO
EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR
OTHER LIABILITY, INCLUDING SPECIAL, INCIDENTAL AND CONSEQUENTIAL DAMAGES, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

---

*This licence was drafted by reading Statamic's own, which is the norm this addon
is listed alongside. No lawyer has reviewed it. The clause most likely to be
tested one day is the engine distinction: this addon can report a document as
having no findings when the heuristics simply cannot see what is wrong with it,
and somebody will eventually read that as a clean bill of health.*
