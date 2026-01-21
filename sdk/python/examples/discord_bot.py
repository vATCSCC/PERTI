#!/usr/bin/env python3
"""
SWIM Discord Bot

Discord bot that posts flight events and TMI alerts to channels.
Useful for virtual airline communities and ARTCC coordination.

Features:
- Posts departures/arrivals to designated channels
- TMI alerts (Ground Stops, GDPs)
- Flight lookup command
- Traffic status command
- Configurable filters per channel

Usage:
    pip install discord.py
    
    Set environment variables:
    export DISCORD_TOKEN=your_discord_bot_token
    export SWIM_API_KEY=your_swim_api_key
    
    python discord_bot.py

Bot Commands:
    !flight DAL123      - Look up flight details
    !traffic KJFK       - Get airport traffic status
    !tmi                - Show active TMIs
    !subscribe dep KJFK - Subscribe channel to JFK departures
    !unsubscribe        - Remove channel subscriptions

Consumer: Virtual Airlines, ARTCC Discord Servers
"""

import os
import sys
import asyncio
from datetime import datetime
from typing import Dict, Set, Optional
from collections import defaultdict

try:
    import discord
    from discord.ext import commands, tasks
except ImportError:
    print("Error: discord.py required. Install with:")
    print("  pip install discord.py")
    sys.exit(1)

from swim_client import SWIMClient
from swim_client.rest import SWIMRestClient


# Configuration
DISCORD_TOKEN = os.getenv('DISCORD_TOKEN')
SWIM_API_KEY = os.getenv('SWIM_API_KEY')

if not DISCORD_TOKEN or not SWIM_API_KEY:
    print("Error: Set DISCORD_TOKEN and SWIM_API_KEY environment variables")
    sys.exit(1)


class SWIMDiscordBot(commands.Bot):
    """Discord bot for SWIM flight events."""
    
    def __init__(self):
        intents = discord.Intents.default()
        intents.message_content = True
        
        super().__init__(
            command_prefix='!',
            intents=intents,
            description='VATSWIM Flight Tracker'
        )
        
        # REST client for commands
        self.swim_rest = SWIMRestClient(SWIM_API_KEY)
        
        # WebSocket client for real-time events
        self.swim_ws: Optional[SWIMClient] = None
        
        # Channel subscriptions: channel_id -> {'departures': [airports], 'arrivals': [airports], 'tmi': bool}
        self.subscriptions: Dict[int, Dict] = defaultdict(lambda: {
            'departures': [],
            'arrivals': [],
            'tmi': False,
        })
        
        # Add commands
        self.add_commands()
    
    def add_commands(self):
        """Register bot commands."""
        
        @self.command(name='flight')
        async def flight_lookup(ctx, callsign: str):
            """Look up flight details by callsign."""
            try:
                response = self.swim_rest.get_flights(callsign=callsign, status='active')
                flights = response.get('data', [])
                
                if not flights:
                    await ctx.send(f"❌ No active flight found for `{callsign}`")
                    return
                
                flight = flights[0]
                embed = self.create_flight_embed(flight)
                await ctx.send(embed=embed)
                
            except Exception as e:
                await ctx.send(f"❌ Error looking up flight: {e}")
        
        @self.command(name='traffic')
        async def traffic_status(ctx, airport: str):
            """Get airport traffic status."""
            airport = airport.upper()
            
            try:
                # Get arrivals and departures
                arr_response = self.swim_rest.get_arrivals(airport, per_page=100)
                dep_response = self.swim_rest.get_departures(airport, per_page=100)
                
                arrivals = arr_response.get('data', [])
                departures = dep_response.get('data', [])
                
                # Count by phase
                arr_inbound = sum(1 for f in arrivals if f.get('progress', {}).get('phase') not in ('LANDED', 'ARRIVED'))
                dep_ground = sum(1 for f in departures if f.get('progress', {}).get('phase') in ('PREFLIGHT', 'DEPARTING'))
                
                embed = discord.Embed(
                    title=f"🛫 {airport} Traffic Status",
                    color=discord.Color.blue(),
                    timestamp=datetime.utcnow()
                )
                
                embed.add_field(name="Inbound", value=str(arr_inbound), inline=True)
                embed.add_field(name="On Ground", value=str(dep_ground), inline=True)
                embed.add_field(name="Total Active", value=str(len(arrivals) + len(departures)), inline=True)
                
                # Check TMI status
                tmi_response = self.swim_rest.get_tmi_programs(airport=airport)
                gs_list = tmi_response.get('ground_stops', [])
                gdp_list = tmi_response.get('gdp_programs', [])
                
                if gs_list:
                    gs = gs_list[0]
                    embed.add_field(
                        name="🛑 GROUND STOP",
                        value=f"Until: {gs.get('end_time', 'TBD')}\nReason: {gs.get('reason', 'N/A')}",
                        inline=False
                    )
                elif gdp_list:
                    gdp = gdp_list[0]
                    embed.add_field(
                        name="⏱️ GDP ACTIVE",
                        value=f"Rate: {gdp.get('program_rate', 'N/A')}/hr\nAvg Delay: {gdp.get('average_delay_minutes', 'N/A')} min",
                        inline=False
                    )
                
                await ctx.send(embed=embed)
                
            except Exception as e:
                await ctx.send(f"❌ Error getting traffic: {e}")
        
        @self.command(name='tmi')
        async def tmi_status(ctx):
            """Show active Traffic Management Initiatives."""
            try:
                response = self.swim_rest.get_tmi_programs(type='all')
                
                gs_list = response.get('ground_stops', [])
                gdp_list = response.get('gdp_programs', [])
                
                embed = discord.Embed(
                    title="🚦 Active TMIs",
                    color=discord.Color.orange(),
                    timestamp=datetime.utcnow()
                )
                
                if not gs_list and not gdp_list:
                    embed.description = "No active TMIs"
                else:
                    # Ground Stops
                    if gs_list:
                        gs_text = "\n".join([
                            f"🛑 **{gs.get('airport')}** - {gs.get('reason', 'N/A')} (until {gs.get('end_time', 'TBD')})"
                            for gs in gs_list[:5]
                        ])
                        embed.add_field(name="Ground Stops", value=gs_text, inline=False)
                    
                    # GDPs
                    if gdp_list:
                        gdp_text = "\n".join([
                            f"⏱️ **{gdp.get('airport')}** - {gdp.get('reason', 'N/A')} ({gdp.get('average_delay_minutes', 0)} min avg)"
                            for gdp in gdp_list[:5]
                        ])
                        embed.add_field(name="Ground Delay Programs", value=gdp_text, inline=False)
                
                embed.set_footer(text=f"GS: {len(gs_list)} | GDP: {len(gdp_list)}")
                await ctx.send(embed=embed)
                
            except Exception as e:
                await ctx.send(f"❌ Error getting TMIs: {e}")
        
        @self.command(name='subscribe')
        @commands.has_permissions(manage_channels=True)
        async def subscribe(ctx, event_type: str, airport: str = None):
            """Subscribe channel to events. Types: dep, arr, tmi"""
            channel_id = ctx.channel.id
            
            if event_type == 'dep' and airport:
                if airport.upper() not in self.subscriptions[channel_id]['departures']:
                    self.subscriptions[channel_id]['departures'].append(airport.upper())
                await ctx.send(f"✅ Subscribed to departures from {airport.upper()}")
            
            elif event_type == 'arr' and airport:
                if airport.upper() not in self.subscriptions[channel_id]['arrivals']:
                    self.subscriptions[channel_id]['arrivals'].append(airport.upper())
                await ctx.send(f"✅ Subscribed to arrivals at {airport.upper()}")
            
            elif event_type == 'tmi':
                self.subscriptions[channel_id]['tmi'] = True
                await ctx.send("✅ Subscribed to TMI alerts")
            
            else:
                await ctx.send("Usage: `!subscribe dep/arr AIRPORT` or `!subscribe tmi`")
        
        @self.command(name='unsubscribe')
        @commands.has_permissions(manage_channels=True)
        async def unsubscribe(ctx):
            """Remove all subscriptions for this channel."""
            channel_id = ctx.channel.id
            self.subscriptions[channel_id] = {
                'departures': [],
                'arrivals': [],
                'tmi': False,
            }
            await ctx.send("✅ Removed all subscriptions for this channel")
        
        @self.command(name='status')
        async def bot_status(ctx):
            """Show bot status and subscriptions."""
            channel_id = ctx.channel.id
            sub = self.subscriptions[channel_id]
            
            embed = discord.Embed(
                title="🤖 SWIM Bot Status",
                color=discord.Color.green()
            )
            
            embed.add_field(
                name="Channel Subscriptions",
                value=(
                    f"Departures: {', '.join(sub['departures']) or 'None'}\n"
                    f"Arrivals: {', '.join(sub['arrivals']) or 'None'}\n"
                    f"TMI Alerts: {'Yes' if sub['tmi'] else 'No'}"
                ),
                inline=False
            )
            
            embed.add_field(
                name="WebSocket",
                value="Connected" if self.swim_ws else "Disconnected",
                inline=True
            )
            
            await ctx.send(embed=embed)
    
    def create_flight_embed(self, flight: dict) -> discord.Embed:
        """Create Discord embed for flight details."""
        identity = flight.get('identity', {})
        plan = flight.get('flight_plan', {})
        pos = flight.get('position', {})
        progress = flight.get('progress', {})
        times = flight.get('times', {})
        
        callsign = identity.get('callsign', 'N/A')
        phase = progress.get('phase', 'Unknown')
        
        # Phase color
        phase_colors = {
            'PREFLIGHT': discord.Color.light_grey(),
            'DEPARTING': discord.Color.green(),
            'CLIMBING': discord.Color.blue(),
            'ENROUTE': discord.Color.blue(),
            'DESCENDING': discord.Color.gold(),
            'APPROACH': discord.Color.orange(),
            'LANDED': discord.Color.green(),
        }
        color = phase_colors.get(phase, discord.Color.default())
        
        embed = discord.Embed(
            title=f"✈️ {callsign}",
            description=f"{plan.get('departure', '????')} → {plan.get('destination', '????')}",
            color=color,
            timestamp=datetime.utcnow()
        )
        
        embed.add_field(name="Aircraft", value=identity.get('aircraft_type', 'N/A'), inline=True)
        embed.add_field(name="Phase", value=phase, inline=True)
        embed.add_field(name="Altitude", value=f"FL{pos.get('altitude_ft', 0) // 100:03d}", inline=True)
        
        embed.add_field(name="Groundspeed", value=f"{pos.get('ground_speed_kts', 0)} kts", inline=True)
        embed.add_field(name="Heading", value=f"{pos.get('heading', 0)}°", inline=True)
        embed.add_field(name="ETA", value=times.get('eta', 'N/A')[-8:-3] if times.get('eta') else 'N/A', inline=True)
        
        if plan.get('route'):
            route = plan['route'][:100] + '...' if len(plan.get('route', '')) > 100 else plan.get('route', '')
            embed.add_field(name="Route", value=f"`{route}`", inline=False)
        
        return embed
    
    async def on_ready(self):
        """Called when bot is ready."""
        print(f"{'='*50}")
        print(f"  SWIM Discord Bot Online")
        print(f"  Logged in as: {self.user}")
        print(f"  Guilds: {len(self.guilds)}")
        print(f"{'='*50}")
        
        # Start WebSocket connection
        await self.start_swim_websocket()
    
    async def start_swim_websocket(self):
        """Start SWIM WebSocket connection for real-time events."""
        self.swim_ws = SWIMClient(SWIM_API_KEY)
        
        @self.swim_ws.on('connected')
        def on_connected(info, timestamp):
            print("✅ SWIM WebSocket connected")
        
        @self.swim_ws.on('flight.departed')
        async def on_departure(event, timestamp):
            await self.handle_departure(event)
        
        @self.swim_ws.on('flight.arrived')
        async def on_arrival(event, timestamp):
            await self.handle_arrival(event)
        
        @self.swim_ws.on('tmi.issued')
        async def on_tmi(event, timestamp):
            await self.handle_tmi(event)
        
        # Subscribe to events
        self.swim_ws.subscribe([
            'flight.departed',
            'flight.arrived',
            'tmi.issued',
            'tmi.modified',
            'tmi.released',
        ])
        
        # Run WebSocket in background
        asyncio.create_task(self.swim_ws.run_async())
    
    async def handle_departure(self, event):
        """Handle departure event."""
        dep = event.dep if hasattr(event, 'dep') else None
        if not dep:
            return
        
        for channel_id, sub in self.subscriptions.items():
            if dep in sub['departures']:
                channel = self.get_channel(channel_id)
                if channel:
                    embed = discord.Embed(
                        title=f"🛫 Departure: {event.callsign}",
                        description=f"{dep} → {event.arr}",
                        color=discord.Color.green()
                    )
                    await channel.send(embed=embed)
    
    async def handle_arrival(self, event):
        """Handle arrival event."""
        arr = event.arr if hasattr(event, 'arr') else None
        if not arr:
            return
        
        for channel_id, sub in self.subscriptions.items():
            if arr in sub['arrivals']:
                channel = self.get_channel(channel_id)
                if channel:
                    embed = discord.Embed(
                        title=f"🛬 Arrival: {event.callsign}",
                        description=f"{event.dep} → {arr}",
                        color=discord.Color.blue()
                    )
                    await channel.send(embed=embed)
    
    async def handle_tmi(self, event):
        """Handle TMI event."""
        for channel_id, sub in self.subscriptions.items():
            if sub['tmi']:
                channel = self.get_channel(channel_id)
                if channel:
                    tmi_type = event.program_type if hasattr(event, 'program_type') else 'TMI'
                    airport = event.airport if hasattr(event, 'airport') else '????'
                    
                    if tmi_type in ('GS', 'GROUND_STOP'):
                        embed = discord.Embed(
                            title=f"🛑 Ground Stop: {airport}",
                            color=discord.Color.red()
                        )
                    else:
                        embed = discord.Embed(
                            title=f"⏱️ {tmi_type}: {airport}",
                            color=discord.Color.orange()
                        )
                    
                    if hasattr(event, 'reason'):
                        embed.add_field(name="Reason", value=event.reason)
                    
                    await channel.send(embed=embed)


def main():
    bot = SWIMDiscordBot()
    
    print("\n🤖 Starting SWIM Discord Bot...")
    print("   Commands: !flight, !traffic, !tmi, !subscribe, !unsubscribe, !status")
    print()
    
    bot.run(DISCORD_TOKEN)


if __name__ == '__main__':
    main()
