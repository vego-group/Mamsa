# RETIRED — do not use this filename

The approvals screen document now lives at:

**`MAMSA-FRONTEND-ADMIN-APPROVALS-SCREEN-v2.md`**

## Why the old name is retired rather than corrected

The 38h → 48h SLA correction was applied to this file on 2026-08-15 and the
repository copy was correct from that day. It was re-issued twice under this
filename and **arrived reading 38h both times**, while a differently-named
document in the same batch arrived correctly.

Re-issued as `…-v2.md` with byte-identical content, it arrived reading **48h**.
Same content, same channel, same sender — only the name changed.

So something between here and the reader **caches by filename**, and it still
holds the 38h copy. Anything requesting this path will keep being served it.

That matters more than the number: this is the document an agent builds the
approvals UI from, so a stale copy re-ships a corrected constant as though it
were current.

**Rule this establishes:** a corrected document gets a new filename. Re-issuing
under the same name is not a delivery mechanism on this channel.
