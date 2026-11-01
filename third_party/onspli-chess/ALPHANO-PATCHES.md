Upstream revision: a8749162fbacac5fc2d3080dd47465f13f5b4a6a.

FEN.php: include a legal en passant target as a pawn capture when constructing SAN candidates. Upstream classified empty target squares as quiet moves, omitting en passant from get_legal_moves(). Covered by tests/board-engines.php. Original MIT notice retained.
