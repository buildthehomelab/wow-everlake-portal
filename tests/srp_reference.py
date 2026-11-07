# Independent port of TrinityCore SRP6.cpp / BattlenetAccountMgr.cpp (forever @5206a72), from the C++ only.
import hashlib, sys
N2 = int("AC6BDB41324A9A9BF166DE5E1389582FAF72B6651987EE07FC3192943DB56050A37329CBB4A099ED8193E0757767A13DD52312AB4B03310DCD7F48A9DA04FD50E8083969EDB767B0CF6095179A163AB3661A05FBD5FAAAE82918A9962F0B93B855F97993EC975EEAA80D740ADBF4FF747359D041D5C33EA71D281E446B14773BCA97B43A23FB801676BD207A436C6481F1D2B9078717461A5B9D32E688F87748544523B524B0D57D5EA77A2775D2ECFA032CFBDBF52FB3786160279004E57AE6AF874E7303CE53299CCC041C7BC308D82A5698F3A8D0C38271AE35F8E9DBFBB694B5C803D89F7AE435DE236D525F54759B65E372FCD68EF20FA7111F9E4AFF73",16)
NG = int("894B645E89E1535BBDAD5B8B290650530801B18EBFBF5E8FAB3C82872A3E9BB7",16)
def bnet(email, pw, salt):
    user = hashlib.sha256(email.upper().encode()).digest().hex().upper()   # GetSrpUsername: ByteArrayToHexStr {:02X}
    xb = hashlib.pbkdf2_hmac('sha512', (user + ':' + pw).encode(), salt, 15000, 64)
    x = int.from_bytes(xb, 'big')                     # BigNumber(xBytes, false) = big endian
    if xb[0] & 0x80: x -= 1 << 512                    # fix[64]=1, little endian 65 bytes
    x %= (N2 - 1)                                     # BN_nnmod
    v = pow(2, x, N2)
    return v.to_bytes((v.bit_length()+7)//8, 'little') # ToByteVector(): little endian, minimal
def grunt(user, pw, salt):
    x = int.from_bytes(hashlib.sha1(salt + hashlib.sha1((user + ':' + pw).encode()).digest()).digest(), 'little')
    v = pow(7, x, NG)
    return v.to_bytes(32, 'little')
for line in sys.stdin:
    kind, a, b, salt = line.rstrip('\n').split('\t')
    salt = bytes.fromhex(salt)
    out = bnet(a, b, salt) if kind == 'bnet' else grunt(a.upper(), b.upper(), salt)
    print(kind, out.hex())
