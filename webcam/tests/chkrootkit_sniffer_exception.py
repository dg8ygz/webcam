#!/usr/bin/env python3
"""The .34 DHCP exception must never hide another sniffer on the same line."""
import subprocess
import unittest

REGEX = r'^(lo: not promisc and no packet sniffer sockets|ens18: PACKET SNIFFER\(/usr/sbin/dhcpcd\[[0-9]+\]\))$'


class SnifferExceptionTests(unittest.TestCase):
    def test_exact_allowlist(self):
        cases = {
            'lo: not promisc and no packet sniffer sockets': True,
            'ens18: PACKET SNIFFER(/usr/sbin/dhcpcd[855])': True,
            'ens18: PACKET SNIFFER(/usr/sbin/dhcpcd[12345])': True,
            'ens19: PACKET SNIFFER(/usr/sbin/dhcpcd[855])': False,
            'ens18: PACKET SNIFFER(/usr/sbin/dhcpcd (deleted)[855])': False,
            'ens18: PACKET SNIFFER(/tmp/dhcpcd[855])': False,
            'ens18: PACKET SNIFFER(/usr/sbin/tcpdump[855])': False,
            'ens18: PACKET SNIFFER(/usr/sbin/dhcpcd[855], /tmp/sniffer[123])': False,
            'ens18: PACKET SNIFFER(/tmp/sniffer[123], /usr/sbin/dhcpcd[855])': False,
            'lo: PACKET SNIFFER(/usr/sbin/tcpdump[123])': False,
            'ens18: PROMISC': False,
            'ens18: PACKET SNIFFER(/usr/sbin/dhcpcd[855]) unexpected': False,
            'ens18: PACKET SNIFFER(/usr/sbin/NetworkManager[855])': False,
        }
        for line, excluded in cases.items():
            with self.subTest(line=line):
                result=subprocess.run(['grep','-E','-v',REGEX],input=line+'\n',text=True,capture_output=True,timeout=5)
                self.assertIn(result.returncode,(0,1))
                self.assertEqual(result.stdout== '',excluded)
                if not excluded: self.assertEqual(result.stdout,line+'\n')


if __name__=='__main__': unittest.main()
